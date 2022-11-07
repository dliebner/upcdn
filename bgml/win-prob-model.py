import sys
import time
import argparse
import requests
from io import StringIO
from itertools import product
from lightgbm import LGBMClassifier
import pandas as pd
import numpy as np

from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import OneHotEncoder

tmp_folder = '/home/bgcdn/bgml/tmp/'

def get_bid_buckets(upper: float = 100.0):
    buckets = list(np.arange(0.01, 0.26, 0.01))
    while True:
        new_val = buckets[-1] * 1.03 + 0.01
        if new_val > upper:
            break
        buckets.append(round(new_val, 2))
    return buckets


def clean_data(df: pd.DataFrame, rename_bid: bool = True) -> pd.DataFrame:
    # Melt data
    value_cols = ["bids_won", "bids_lost"]
    id_cols = [col for col in df.columns if col not in value_cols]
    agg_melt = pd.melt(
        df,
        id_vars=id_cols,
        value_vars=value_cols,
        value_name="bids",
        var_name="bid_won",
    )

    # Clean values and column names
    agg_melt.bid_won = agg_melt.bid_won.replace({"bids_won": 1, "bids_lost": 0})
    if rename_bid:
        agg_melt = agg_melt.rename(
            columns={"bid": "bid_bucket", "bid_amount": "bid_bucket"}
        )

    return agg_melt


def train_model(
    df: pd.DataFrame, features: list[str] = ["bid_bucket"], **lgb_params
) -> LGBMClassifier:

    # Fit model
    mc = [1 if feat == "bid_bucket" else 0 for feat in features]
    clf = LGBMClassifier(mc=mc, **lgb_params)
    clf.fit(df[features], df.bid_won, sample_weight=df.bids)

    return clf


def train_logistic_model(
    df: pd.DataFrame, features: list[str] = ["bid_bucket"]
) -> LGBMClassifier:

    # Find numeric and non-numeric columns
    num_cols = df.select_dtypes(include=[np.number]).columns
    num_feat = list(set(features).intersection(num_cols))
    str_feat = list(set(features).difference(num_cols))

    # Define model
    col_trans = ColumnTransformer(
        [
            ("onehot", OneHotEncoder(handle_unknown="ignore"), str_feat),
            ("pass", "passthrough", num_feat),
        ]
    )
    clf = Pipeline(
        [
            ("onehot", col_trans),
            ("clf", LogisticRegression(C=1)),
        ]
    )
    clf.fit(df[features], df.bid_won, clf__sample_weight=df.bids)

    return clf


def gen_predictions(
    clf: LGBMClassifier,
    df: pd.DataFrame,
    bid_feat: str,
    features: list,
) -> pd.DataFrame:
    # Create possible iterations dataframe
    poss_values = []
    for feat in features:
        if feat == bid_feat:
            poss_values.append(get_bid_buckets())
        else:
            poss_values.append(df[feat].unique().tolist())
    test_data = pd.DataFrame(product(*poss_values), columns=features)
    test_data = test_data.astype(df[features].dtypes)

    # Generate predictions
    test_data["prob_win"] = clf.predict_proba(test_data)[:, 1]

    # Sort by bid feature last
    sort_order = test_data.columns.tolist()
    sort_order.append(sort_order.pop(sort_order.index(bid_feat)))
    sort_order.remove("prob_win")
    test_data = test_data.sort_values(sort_order)

    return test_data


def get_last_cross(pred_diff: pd.Series) -> pd.Series:
    # Mark the last time pred_diff crosses zero as True, else False
    last_cross_mask = np.zeros(pred_diff.shape, dtype=bool)
    tail_bigger = np.sign(pred_diff)
    cross_ind = np.where(tail_bigger != tail_bigger.shift(1))[0]
    last_cross = cross_ind[-1]
    last_cross_mask[last_cross] = True
    return pd.Series(last_cross_mask)


def combine_lookups(
    lookup: pd.DataFrame,
    smooth_lookup: pd.DataFrame,
    bid_groups: pd.Series,
    pred_feat: str = "prob_win",
) -> pd.DataFrame:
    # Get the last time log-reg predictions cross lightgbm per group
    lookup["pred_diff"] = smooth_lookup[pred_feat] - lookup[pred_feat]
    last_cross_mask = (
        lookup.groupby(bid_groups)["pred_diff"]
        .apply(get_last_cross)
        .reset_index(level=0, drop=True)
    )
    last_cross_mask.index = lookup.index

    # Combine last crosses with bid groups
    use_smooth = -1 * (bid_groups.copy() - bid_groups.min())
    use_smooth += last_cross_mask.cumsum()
    use_smooth = use_smooth.astype(bool)

    # Use log-reg when it's crossed for the last time per bid group
    lookup.loc[use_smooth, pred_feat] = smooth_lookup.loc[use_smooth, pred_feat]

    return lookup.drop(columns="pred_diff")


def get_bid_group_id(lookup: pd.DataFrame, bid_feat: str, pred_feat: str = "prob_win"):
    """This function creates a bid group id that iterates every time a secondary
    feature changes.

    Parameters
    ----------
    lookup : pd.DataFrame
        _description_
    bid_feat : str
        _description_
    pred_feat : str, optional
        _description_, by default "prob_win"

    Returns
    -------
    _type_
        _description_
    """
    mask = np.zeros(lookup.shape[0], dtype=bool)
    for col in lookup.columns:
        if col not in [bid_feat, pred_feat]:
            mask = mask | (lookup[col] != lookup[col].shift(1))
    return pd.Series(mask, index=lookup.index).cumsum()


def filter_min_diff(ser: pd.Series, min_diff: float = 0.005) -> pd.Series:
    # Filter a monotonically increasing series to every time the value has increased
    # by at least min_diff
    mask = np.zeros(ser.shape, dtype=bool)
    mask[0] = True
    curr_val = ser.iloc[0]
    for idx in range(1, len(ser)):
        new_val = ser.iloc[idx]
        if new_val >= (curr_val + min_diff):
            curr_val = new_val
            mask[idx] = True
    return pd.Series(mask)


def condense_lookup(
    lookup: pd.DataFrame,
    bid_groups: pd.Series,
    pred_feat: str = "prob_win",
    min_diff: float = 0.005,
) -> pd.DataFrame:
    condense_mask = lookup.groupby(bid_groups)[pred_feat].apply(
        filter_min_diff, min_diff=min_diff
    )
    condense_mask.index = lookup.index
    return lookup[condense_mask]


def parse_args():
    ### Set up command line arguments
    parser = argparse.ArgumentParser(description="Train LightGBM Bidding Model")
    parser.add_argument(
        "--ad_unit_id", type=int, help="The id of the ad unit"
    )
    parser.add_argument(
        "--postback_password", type=str, help="Password for posting back to the server"
    )
    parser.add_argument(
        "--bid_feature",
        type=str,
        default="bid_bucket",
        help="Name of the bid amount feature",
    )
    parser.add_argument(
        "--other_num_features",
        nargs="*",
        default=[],
        help="Additional numerical features to use in model (separated by space)",
    )
    parser.add_argument(
        "--other_cat_features",
        nargs="*",
        default=[],
        help="Additional categorical features to use in model (separated by space)",
    )

    return parser.parse_args()


def read_file(filepath: str) -> pd.DataFrame:
    if filepath.endswith("xlsx"):
        return pd.read_excel(filepath)
    elif filepath.endswith("csv"):
        return pd.read_csv(filepath)
    else:
        extension = filepath.split(".")[-1]
        raise ValueError(f"Unrecognized {extension = }")


def main():
    # Benchmarking
    time_start = time.time()

    # Retrieve command line arguments
    args = parse_args()

    ### Get up-to-date data from server
    print("=== Downloading CSV data from server (1/6) ===")
    time_start_task = time.time()
    r = requests.post(
        url="https://bid.glass/bidMLTest.php", stream=True, data={"pass": args.postback_password, "adUnitId": args.ad_unit_id}
    )
    if r.encoding is None:
        r.encoding = "utf-8"

    responseText = ""

    i = 0
    for line in r.iter_lines(decode_unicode=True):
        if line:
            i = i + 1
            if i <= 2:
                print("\t" + line)
            responseText += line + "\n"
    print("Data downloaded and read in " + str(time.time() - time_start_task))

    ### Open input file
    print("\nReading input file...")
    time_start_task = time.time()
    try:
        df = pd.read_csv(StringIO(responseText))
    # except FileNotFoundError:
    #  print("Input file `%s` not found. Aborting." % args.csv)
    #  exit()
    except:
        raise
    print("Read input in " + str(time.time() - time_start_task))

    # Clear up a tiny bit of RAM
    responseText = None

    print(
        "Input file contains %d rows and %d columns." % (np.size(df, 0), np.size(df, 1))
    )

    # Clean data
    print("\n=== Cleaning data (2/6) ===")
    time_start_task = time.time()
    df = clean_data(df, rename_bid=False)
    df[args.other_cat_features] = df[args.other_cat_features].astype("category")

    # Check for features in data
    features = [args.bid_feature] + args.other_cat_features + args.other_num_features
    missing_features = [col for col in features if col not in df.columns]
    if len(missing_features) > 0:
        raise ValueError(
            f"Features {missing_features} not present in data. Please manually"
            " specify correct feature names"
        )
    print("Cleaned in " + str(time.time() - time_start_task))

    # Train models
    print("\n=== Training model (3/6) ===")

    time_start_task = time.time()
    clf = train_model(df, features=features)
    print("Trained lightgbm in " + str(time.time() - time_start_task))

    time_start_task = time.time()
    log_reg = train_logistic_model(df, features=features)
    print("Trained logistic in " + str(time.time() - time_start_task))

    # Create lookup table
    print("\n=== Building lookup table (4/6) ===")

    # Built separate tables
    time_start_task = time.time()
    lookup_table = gen_predictions(
        clf, df, bid_feat=args.bid_feature, features=features
    )
    print("LGBM lookup table built in " + str(time.time() - time_start_task))

    time_start_task = time.time()
    lookup_table_lr = gen_predictions(
        log_reg, df, bid_feat=args.bid_feature, features=features
    )
    print("Regression lookup table built in " + str(time.time() - time_start_task))

    time_start_task = time.time()
    bid_groups = get_bid_group_id(lookup_table, bid_feat=args.bid_feature)
    print(
        "Secondary feature change indexes calculated in "
        + str(time.time() - time_start_task)
    )

    # Combine lookup tables
    time_start_task = time.time()
    lookup_table = combine_lookups(
        lookup_table,
        lookup_table_lr,
        bid_groups=bid_groups,
    )
    print("Combined lookup table built in " + str(time.time() - time_start_task))

    # Condense lookup table
    time_start_task = time.time()
    lookup_table = condense_lookup(lookup_table, bid_groups=bid_groups)
    print("Condensed lookup table built in " + str(time.time() - time_start_task))

    # Save lookup table
    time_start_task = time.time()
    csv_file = (
        tmp_folder + "tmp_bid_probability_" + str(args.ad_unit_id) + "_" + str(time.time()) + ".csv"
    )
    lookup_table.to_csv(csv_file, index=False)
    print("Saved in " + str(time.time() - time_start_task))

    # TODO: Upload or return to server
    print("\n=== Posting to server (5/6) ===")

    # TODO: Delete csv file
    print("\n=== Deleting CSV (6/6) ===")

    print("\nRun completed in " + str(time.time() - time_start))


if __name__ == "__main__":
    main()
