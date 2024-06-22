# ROT - Ring of Trust

ROT is an endeavor to present and alternative to SPV servers such as electrumX to support Lite Clients.
After 10 years most of the challenges described in the concept of ROT ([Ring of Trust](https://github.com/Electronic-Gulden-Foundation/ROT)) are still valid. But more challenges appeared om the horizon: To summarize:

- **Most important**: As of June 22, 2024, there are no SPV servers available for DEM, PAK, SLG, and RUBTC. Since CC is an initiative to stimulate decentralization, a basic function such as an SPV service should principally be provided by each individual community. Only as a backup and for mutual support could it be provided cross-community.
- The amount of full Bitcoin nodes keeps decreasing
- The amount of publicly available and reliable electrumX, fulcrum and electrs nodes is very limited ([bitcoin-eye](https://1209k.com/bitcoin-eye/ele.php))
- A service like bitcoin-eye is necessary for node discovery
- Except for the _**height**_ there is no way to verify the integrity of a single SPV server
- There is no incentive so maintain an SPV service while there is a cost associated
- Many cryptocurrency communities have a hard time keeping sufficient nodes alive, let alone a healthy set of SPV services
- Individual services struggle with performance issues
- To accommodate all address-types and transaction verification is impossible end needlessly complex ([A survey](https://www.quantabytes.com/articles/a-survey-of-bitcoin-transaction-types)). If a lite client only generates Legacy and Bech addresses, it will only be interested in outputs to those address types
- Using sockets, while disabling websockets, most SPV services an not accessible from the browser.
- CORS (**Cross-Origin Resource Sharing**), a modern browser requirement, restricts the possibilities for decentralization 
- SPV has become a "convenient" one size fits all service. To enable Lite Clients these ONLY need an up-to-date list of unspent transactions and a way to submit outgoing transactions.  Therefor a lot of complexity can be omitted
- Lite Clients will ask the same question multiple times (eg. what is my balance) while their input addresses didn't change and while requiring a balance check of all these addresses. This can be done much more efficiently,

