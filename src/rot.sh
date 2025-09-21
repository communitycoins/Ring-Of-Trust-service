#!/usr/bin/env bash
#Example shell script to start ROT server

[ -z "$1" ] && { echo "Usage: $0 <coin>"; exit 2; }

docker rm -f "rot-$1" >/dev/null 2>&1 || true

ECOINCORE="/var/data/communitycoins"

COIN="$1"

docker run -d \\
 --name "rot-$COIN" \\
 --network host \\
 --log-driver=none \\
-v "$ECOINCORE:$ECOINCORE" \\
cc-php-rot:8.3 php \\
 -d memory\_limit=2G \\
 -d log\_errors=1 \\
 -d error\_reporting=E\_ALL \\
 -d display\_errors=1 \\
 -d error\_log="/var/data/communitycoins/rot/rot\_$COIN.log" \\
$ECOINCORE/rot/rot.php --config="$ECOINCORE/$COIN/rot/rot.conf"
