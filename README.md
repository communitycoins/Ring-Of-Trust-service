ROT aims to provide an alternative to SPV servers like ElectrumX to support Lite Clients. After 10 years, many of the challenges outlined in the concept of ROT (Ring of Trust)[https://github.com/Electronic-Gulden-Foundation/ROT] remain relevant. However, new challenges have also emerged. To summarize:

Most important: As of June 22, 2024, there are no SPV servers available for DEM, PAK, SLG, and RUBTC. Since CC is an initiative to stimulate decentralization, a basic function such as an SPV service should principally be provided by each individual community. Only as a backup and for mutual support should it be provided cross-community.

- The number of full Bitcoin nodes continues to decrease.
- The number of publicly available and reliable ElectrumX, Fulcrum, and Electrs nodes is very limited (e.g., bitcoin-eye).
- A service like bitcoin-eye is essential for node discovery.
- Apart from the block height, there is no way to verify the integrity of a single SPV server.
- There is no incentive to maintain an SPV service due to associated costs.
- Many cryptocurrency communities struggle to keep sufficient nodes alive, let alone maintain a healthy set of SPV services.
- Individual services often face performance issues.
- Accommodating all address types and transaction verifications is overly complex and unnecessary. A (survey)[https://www.quantabytes.com/articles/a-survey-of-bitcoin-transaction-types] shows that if a lite client only generates Legacy and Bech addresses, it will only need outputs to those address types.
- Using sockets while disabling WebSockets makes most SPV services inaccessible from browsers.
- CORS (Cross-Origin Resource Sharing), a modern browser requirement, restricts decentralization possibilities.
- SPV has become a "one size fits all" service. To enable Lite Clients, they only need an up-to-date list of unspent transactions and a way to submit outgoing transactions, allowing for significant simplification.
- Lite Clients repeatedly ask the same questions (e.g., what is my balance) while their input addresses remain unchanged, necessitating balance checks for all these addresses. This process can be made much more efficient.
- Using memory management can improve the original ROT design dramatically
- Creating a docker-image for each community coin would stimualate the proppagation of individual rings.

The procedure to implement the network and verify trust is not yet described, but can easiliy be implemented. To bootstrap a ring  initially simple publication would suffice. 