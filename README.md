> **Canonical source:** https://git.communitycoins.org/Rings-Of-Trust/Ring-Of-Trust-service  
> Mirrors (GitLab/GitHub) may be read-only and may lag behind.

# ROT Server

This project provides the reference implementation of a **ROT (Ring of Trust)** SPV server for [CommunityCoins](https://gitlab.com/c4319/wallet).  
The current implementation is written in PHP (`rot.php`). It provides an **SPV layer itself**, enabling wallets to interact with the blockchain in a decentralized and lightweight way.  

⚠️ A separate **proxy layer** is envisioned for near future release. That proxy will serve as a public relay and directory: listing supported coins, and per coin the available ROT servers (IP, port, and status).

---

## What is Ring of Trust (ROT)?

The **Ring of Trust (ROT)** is a decentralized architecture for CommunityCoins wallets.  
Instead of relying on a open accessible central servers, ROT proxies distribute trust across multiple teams and nodes:

- Each **coin team** runs one or more ROT proxies. 
- Anyone that has a running core-wallet can run a ROT server out of loyalty to a coin and its community
- Proxies act as neutral relays — they do not validate or censor, only serve.  
- Both wallets and SPV(ROT) servers discover proxies from a central bootstrap list, then operate autonomously.  
- SPV services must conform strictly to protocol. Misbehaving nodes are warned or excluded.  

ROT separates **coin governance** (teams, proxies) from **service provision** (SPV nodes).  
This ensures no single point of failure and keeps wallet UX simple for end-users.

---

## Documentation

Additional background and design documents can be found in the [`/docs`](./docs) directory:

- [Motivation](./docs/motivation.md) – why ROT is needed, including ecosystem challenges (declining nodes, lack of incentives, browser restrictions).  
- [Considerations](./docs/considerations.md) – design choices and trade-offs in the ROT architecture.  

---

## Further Reading

Three articles further motivate design choices for the ROT implementation:

- [Why We Choose Legacy](https://medium.com/@support_4739/why-we-choose-legacy-3376b8f9415c)  
- [Rethinking SPV](https://medium.com/@support_4739/rethinking-spv-toward-a-stateless-peer-verified-backend-layer-for-blockchain-light-clients-7fd5e2906601)  
- [Enhanced Stateless, Poll-Optimized Light Client Network Architecture: A Technical Analysis](https://medium.com/@support_4739/title-enhanced-stateless-poll-optimized-light-client-network-architecture-a-technical-analysis-9f54243e71f5)  

---

## Status

**second release (v0.2)**. 
Accomodated to serve in a zero-touch docker compose setup (service rot:) using environment variables : [e-Gulden example](https://git.communitycoins.org/Rings-Of-Trust/e-gulden/src/branch/master/docker-compose.yml)

**first release (v0.1.0)**.  
**Early stage software** – many features are still **TODO**, and breaking changes are expected.  

The main goal of this release is to:  
- **Understanding the design** – how ROT structures SPV services and governance.  
- **Testing the implementation** – teams can already deploy and experiment with `rot.php`.  
- **Gathering feedback** – insights from early users will guide improvements toward a stable v1.0.0.  

---

## Contributing

Contributions are welcome!  

This project is still early stage, so even small contributions make a big difference:  
- Testing and reporting bugs.  
- Sharing feedback on the [design considerations](./docs/considerations.md).  
- Improving documentation.  
- Suggesting or discussing features.  

Don’t hesitate — your perspective helps shape ROT into a robust, decentralized SPV layer.  
See the [Issue Board](../../-/boards) for an overview of tasks and progress.  
You can also browse the full [Issues list](../../-/issues).

## Requirements

- PHP 7.4 - 8.3 CLI (with `shmop` enabled)  
- Linux environment (tested on Ubuntu / Docker)  
- Access to a local blockchain core (tested on egulden(EFL), auroracoin(AUR), canadaecoin(CDN), deutche emark(DEM) and cryptoescudo(CESC))  

---

## ▶️ Usage

Run the server:

```
#!/usr/bin/env bashbash
php rot.php --config="/var/<ecoincore>/rot/<coin>/rot.conf" 
```


If no php is available we would advice installing/using docker and create a virtual php-image as such:

***Dockerfile:***

```Dockerfile
FROM php:8.3-cli

RUN docker-php-ext-install shmop
```
***bash / script:***
```bash
docker build -t cc-php-rot:8.3 .
```

and use it as such

***bash / script:***
```#!/usr/bin/env bash
\[ -z "$1" ] \&\& { echo "Usage: $0 <coin>"; exit 2; }

docker rm -f "rot-$1" >/dev/null 2>\&1 || true

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
```

If you run multiple core-wallets you might have this directory-structures
```text
communitycoins/
├── rot/
│   ├── Dockerfile
│   ├── rot.php
│   └── rot.sh
├── aur/
│   ├── blockchain/
│   └── rot/
│       └── rot.conf
├── boli/
│   ├── blockchain/
│   └── rot/
│       └── rot.conf
├── cdn/
├── cesc/
├── dem/
├── efl/
├── fjc/
├── pak/
├── rubtc/
├── slg/
├── efl/
```
With these commands you have ease control over the ROT infrastructure:
```bash
# Show running spv-servers and their status
docker ps -a --filter name='rot-' --format 'table {{.Names}}\t{{.Status}}'

# start a single server
./rot.sh efl

# stop a single server
docker stop rot-efl
```
