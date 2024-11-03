# Ring of Trust 
ROT aims to provide an alternative to SPV servers like ElectrumX to support Lite Clients. 10 years after its definition, many of the challenges outlined in the concept of ROT (Ring of Trust)[https://github.com/Electronic-Gulden-Foundation/ROT] remain relevant. However, new challenges have also emerged. 

Within the context of CommunityCoins, as of June 22, 2024, there are no SPV servers available for DEM, PAK, SLG, and RUBTC. Since CC is an initiative to stimulate decentralization, a basic function such as an SPV service should principally be provided by each individual community. If teams want to provide a backup for others the threshold for running a single spv server must be lowered significantly.

## Considerations
- The number of full Bitcoin nodes continues to decrease. One of the reasons is the power required to run a single node. Running an SPV server in parallel requires even more power so there is a high pressure to improve SPV performance.
- The number of publicly available and reliable ElectrumX, Fulcrum, and Electrs nodes is very limited (e.g., bitcoin-eye). The introduction of a multicoin light wallet involves the extension of a reliable dedicated SPV network.
- A service like bitcoin-eye is essential for node discovery. To maintain a stable wallet-service the spv-layer needs to be managed.
- Apart from the block height, there is no way to verify the integrity of a single SPV server. SPV-servers, each having a full view in their blockchain could easily verify others
- There is no incentive to maintain an SPV service other than loyalty to the blockchain. This is an unhealthy situation.
- Many cryptocurrency communities struggle to keep sufficient nodes alive, let alone maintain a healthy set of SPV services.
- Individual SPV services often face performance and stability issues. The promise of light wallets is to make the wallet function portable and to dramatically enhance the amount of transactions. It is easy to see that the spv-layer will soon become a bottleneck.
- Electrum as an example is based on old code and an even older design. A rebuild from scratch and a design tailored to service a specialized wallet for CommunityCoins could streamline the entire layer.
- Accommodating all address types and transaction verifications is overly complex and unnecessary. A (survey)[https://www.quantabytes.com/articles/a-survey-of-bitcoin-transaction-types] shows that if a lite client would only generate Legacy addresses, the SPV service would only need to monitor transaction outputs to those address types.
- Most SPV services are inaccessible from browsers because they have disabled websockets.
- CORS (Cross-Origin Resource Sharing), a modern browser requirement, restricts decentralization possibilities, but it has become an element of modern browsers. The user has no possibility to obtain cross domain services unless CORS has been switched off by that service.
- Browsers require trusted certificates from external services. This poses a burden on the delivery of those services. A proxy solution could solve this: An spv-server could advocate to a proxy-server and the proxyserver would be accessed by the light client.
- Creating a docker-image for each community coin would stimualate the proppagation of individual rings.

The procedure to implement the network and verify trust is not yet described, but can easiliy be implemented. To bootstrap a ring  initially simple publication would suffice. 