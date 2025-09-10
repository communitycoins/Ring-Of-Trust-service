# Ring of Trust 
ROT aims to provide an alternative to SPV servers like ElectrumX to support Lite Clients. 10 years after its definition, many of the challenges outlined in the concept of ROT (Ring of Trust)[https://github.com/Electronic-Gulden-Foundation/ROT] remain relevant. However, new challenges have also emerged. 

Within the context of CommunityCoins, as of June 22, 2024, there are no SPV servers available for DEM, PAK, SLG, and RUBTC. Since CC is an initiative to stimulate decentralization, a basic function such as an SPV service should principally be provided by each individual community. If teams want to provide a backup for others the threshold for running a single spv server must be lowered significantly.

## Considerations
- The number of full Bitcoin nodes continues to decrease. One of the reasons is the capacity required to run a single node. Running an SPV server in parallel requires even more capacity so there is a need to improve SPV performance.
- The number of publicly available and reliable ElectrumX, Fulcrum, and Electrs nodes is very limited (e.g., bitcoin-eye). The introduction of a multicoin light wallet involves the enhancement of a reliable dedicated SPV network.
- A service like bitcoin-eye is essential for spv-peer discovery. To maintain a stable wallet-service the spv-layer needs to be stable and discoverable.
- Apart from the actual block height, there is no way to verify the integrity of a single SPV server. SPV-servers, each having a full view in their blockchain, should be able to verify others. This is essential since lite-clients delegate trust. That is the main reason why we propose Ring Of Trust
- There is no incentive to maintain an SPV service other than loyalty to the blockchain. This is an unhealthy situation.
- Many cryptocurrency communities struggle to keep sufficient nodes alive, let alone maintain a healthy set of SPV services.
- Individual SPV services often face performance and stability issues. The promise of light wallets is to make the wallet function portable and to dramatically enhance the amount of transactions. It is easy to see that the spv-layer could easily become a bottleneck.
- Electrum as an example is based on old code and an even older design. A rebuild from scratch and a design tailored to service a specialized wallet for CommunityCoins could streamline the entire layer.
- Accommodating all address types and transaction verifications is overly complex and unnecessary. A [survey](https://www.quantabytes.com/articles/a-survey-of-bitcoin-transaction-types) shows the popularity of p2pkh transaction types. This decreases because of the introduction of segwit and bech-addressing. If a lite client would only generate Legacy addresses, the SPV service would only need to monitor transaction outputs to those address types. That significantly decreases the spv-load.
- Most SPV services are inaccessible from browsers because socket-access is disabled.
- CORS (Cross-Origin Resource Sharing), a modern browser requirement, restricts decentralization possibilities, but it has become an element of modern browsers. The user has no possibility to obtain cross domain services unless CORS has been explicitly allowed by that service.
- Browsers require trusted certificates from external parties. This poses a burden on the delivery of those services. A proxy solution could solve this: An spv-server could advocate to a proxy-server and the proxyserver would be accessed by the light client.
- Creating a docker-image for each community coin would stimualate the propagation of individual rings.

Three articles further motivate design choices for the ROT-implementation
- [Why We Choose Legacy](https://medium.com/@support_4739/why-we-choose-legacy-3376b8f9415c)
- [Rethinking SPV](https://medium.com/@support_4739/rethinking-spv-toward-a-stateless-peer-verified-backend-layer-for-blockchain-light-clients-7fd5e2906601)
- [Enhanced Stateless, Poll-Optimized Light Client Network Architecture: A Technical Analysis](https://medium.com/@support_4739/title-enhanced-stateless-poll-optimized-light-client-network-architecture-a-technical-analysis-9f54243e71f5)

## Source rot.php

SPV-server that serves legacy-only light clients P2PKH-addresses.

Uses a single line pipe-delimited configuration file: rot.conf 
  - coin-tikker| 
  - rpc-user|
  - rpc-ww|
  - rpc-port|
  - rot service-port|
  - block data directory

Except for PHP >= 7.4 (2019) there are zero dependancies except for the core-wallet is services. 
If you have docker available there is no need for PHP on your host either. Just use this Dockerfile to build a virtual php-image:

Dockerfile:
```
FROM php:8.3-cli
RUN docker-php-ext-install shmop
```
Build the image from the command-line with: docker build -t cc-php-rot:8.3 .

In the example that follows rot.php is places at `/var/data/communitycoins/rot`,
the corewallet datadirectory at `/var/data/communitycoins/<coin>/blockchain`,
the rot datadirectory at `/var/data/communitycoins/<coin>/rot`

rot is started with `./rot.sh <coin>`,
rot is stopped with `docker stop rot-<coin>`;
if you service multiple coins use `docker ps -a --filter name='rot-' --format 'table {{.Names}}\t{{.Status}}'` for an overview

Errors are logged per coin at `/var/data/communitycoins/rot/rot_<coin.log>`

A monitor-logfile is available at `/var/data/communitycoins/<coin>/rot/rot.log`

rot.sh:
```#!/usr/bin/env bash
[ -z "$1" ] && { echo "Usage: $0 <coin>"; exit 2; }

docker rm -f "rot-$1" >/dev/null 2>&1 || true

ROOT="/var/data/communitycoins"
COIN="$1"

docker run -d \
  --name "rot-$COIN" \
  --network host \
  --log-driver=none \
  -v "$ROOT:$ROOT" \
  cc-php-rot:8.3 php \
    -d memory_limit=2G \
    -d log_errors=1 \
    -d error_reporting=E_ALL \
    -d display_errors=1 \
    -d error_log="/var/data/communitycoins/rot/rot_$COIN.log" \
  $ROOT/rot/rot.php --config="$ROOT/$COIN/rot/rot.conf"
```
