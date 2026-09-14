#!/usr/bin/env bash
# Runs every EduFlex test suite. Needs PHP only: no database, no web server.
#   ./run_tests.sh              (Mac/Linux)
#   C:\xampp\php\php.exe tests\auth_test.php     (Windows, one at a time)
set -u
PHP="${PHP:-php}"
fail=0
for suite in tests/auth_test.php tests/extract_test.php tests/ai_test.php tests/questions_test.php tests/attempts_test.php tests/chat_test.php; do
  echo ""
  "$PHP" "$suite" || fail=1
done
echo ""
if [ "$fail" -eq 0 ]; then echo "ALL SUITES PASSED"; else echo "SOME SUITES FAILED"; fi
exit "$fail"
