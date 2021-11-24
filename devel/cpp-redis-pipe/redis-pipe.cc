// redis-pipe.cc -- equivalent of redis-pipe.sh

# include <cstdlib>
# include <cstdio>
# include <cctype>
# include <string>
# include <atomic>
# include <thread>
# include <chrono>

# include <cpp_redis/cpp_redis>

# define REDIS_PIPE_ASYNC_COMMIT true

int main() {
   try {
      cpp_redis::client client;
      client.connect();
      for (;;) {
         std::atomic<bool> ignore_cb_err = false;
         struct to_next_line {};

         const auto check_stdin = [&]() noexcept{
            if (!std::ferror(stdin) && !std::feof(stdin)) return;
         # if REDIS_PIPE_ASYNC_COMMIT // graceful shutdown procedure needed
            std::this_thread::sleep_for(std::chrono::seconds(5));
            ignore_cb_err = true, client.disconnect(true);
            std::this_thread::sleep_for(std::chrono::seconds(1));
         # endif
            if (std::ferror(stdin))
               std::perror("Cannot read"), std::exit(EXIT_FAILURE);
            std::exit(EXIT_SUCCESS);
         };
         const auto get_int = [&](){
            long long res; int ch;

            // skip until start of a field
            while (std::isblank(ch = std::getchar())); check_stdin(); std::ungetc(ch, stdin);
            if (std::isspace(ch)) throw to_next_line{}; // prevent skiping more whitespace in scanf (including NL)
            // read in a number
            int count = std::scanf("%11lld", &res); check_stdin();
            if (count != 1 || (int)res != res) throw to_next_line{}; // also check for numeric overflow
            // check that no undelimited garbage follows it
            ch = std::getchar(); check_stdin(); std::ungetc(ch, stdin);
            if (!std::isblank(ch) && ch != '\n') throw to_next_line{};

            return (int)res;
         };
         const auto get_str = [&](){
            std::string res; int ch;

            // skip until start of a field
            while (std::isblank(ch = std::getchar())); check_stdin(); std::ungetc(ch, stdin);
            // read in a string (consisting of graphic chars)
            while (std::isgraph(ch = std::getchar())) res.push_back(ch); check_stdin(); std::ungetc(ch, stdin);
            if (!res.size()) throw to_next_line{};
            // check that no undelimited garbage follows it (i.e., some control char)
            ch = std::getchar(); check_stdin(); std::ungetc(ch, stdin);
            if (!std::isblank(ch) && ch != '\n') throw to_next_line{};

            res.shrink_to_fit();
            return res;
         };

         try {
            int ts = get_int(), bytes = get_int(), status = get_int(); // add more variables/fields if you need to (use also `get_str`)
            std::string domain = get_str(), uri = get_str();

            while (std::getchar() != '\n') check_stdin(); // ignore the rest of line
            static const cpp_redis::reply_callback_t cb = [&](auto &&reply) noexcept
               { if (!ignore_cb_err && reply.is_error()) std::fprintf(stderr, "Redis error: %s\n", reply.error().c_str()); };
            // make redis requests
            client.incrby("bgcdn:bw_chunk", bytes, cb); // Cumulative chunk bandwidth
            for (int expires = ts + 1; expires <= ts + 30; ++expires) { // Rolling 30s bandwidth
               char buf[sizeof "bgcdn:bw_30sec_exp_+2147483648"];
               const std::string key = (std::sprintf(buf, "bgcdn:bw_30sec_exp_%d", expires), buf);
               client.incrby(key, bytes, cb).expireat(key, expires, cb);
            }
            if( status == 404 && domain == "${BGCDN_HOSTNAME}" ) {
               client.hsetnx("bgcdn:404_uris", uri, "1");
            }
            // commit requests
            # if REDIS_PIPE_ASYNC_COMMIT
               client.commit();
            # else
               client.sync_commit();
            # endif
         }
         catch (to_next_line) { // silently ignore invalid records, except after EOF (which leads to exit)
            while (std::getchar() != '\n') check_stdin(); // ignore the rest of line
         }
      }
   }
   catch (const cpp_redis::redis_error &ex)
      { std::fprintf(stderr, "Redis fatal error: %s\n", ex.what()), std::exit(EXIT_FAILURE); }
}
