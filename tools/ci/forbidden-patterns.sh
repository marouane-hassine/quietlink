#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Fails when forbidden primitives, remote resources or weak randomness appear in the code.
set -eu
status=0
check() {
    if grep -RInE "$1" $2 2>/dev/null | grep -v 'forbidden-patterns.sh'; then
        echo "Forbidden pattern found: $3" >&2
        status=1
    fi
}
check 'sodium_crypto_aead_aes256gcm_(encrypt|decrypt|keygen)\(' 'src bin' 'sodium AES-GCM (requires AES-NI, §9.2)'
check 'Math\.random\(' 'frontend/src' 'Math.random is not a CSPRNG'
check '\b(mt_rand|rand|uniqid|lcg_value)\(' 'src' 'non-cryptographic PHP randomness'
check 'https?://(fonts\.googleapis|cdn\.|unpkg|jsdelivr|cdnjs)' 'frontend/src templates' 'third-party resources'
check 'innerHTML\s*=' 'frontend/src' 'innerHTML sink'
check '(doctrine/dbal|predis|ext-pdo|ext-redis|symfony/lock)' 'composer.json' 'database, Redis or Lock dependency'
exit $status
