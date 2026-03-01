# Motivation for ROT

The **Ring of Trust (ROT)** did not arise in a vacuum. It is a response to several ecosystem-wide pressures that make decentralized, lightweight wallet infrastructure increasingly necessary.

---

## Declining Full Node Participation

- The number of full Bitcoin nodes continues to decrease.  
- One major factor is the **capacity required** to run a single node: storage, bandwidth, and CPU requirements grow steadily.  
- Running an SPV server in parallel amplifies these demands.  
- **Motivation for ROT**: improving SPV performance and reducing resource requirements is essential to keep decentralized access practical.

---

## Limited Availability of SPV Nodes

- The number of publicly available and reliable ElectrumX, Fulcrum, and Electrs nodes is very limited.
- A service like [**bitcoin-eye**](https://1209k.com/bitcoin-eye/ele.php) is currently essential for SPV peer discovery.  
- This scarcity makes wallets fragile: if a few nodes fail or misbehave, users lose reliable access.  
- To maintain a stable wallet service, the SPV layer itself must be **stable and discoverable**. 
- A **multicoin light wallet** depends on a stronger backbone than the current scattered set of public servers.  
- **Motivation for ROT**: establish a dedicated and reliable SPV network that can support multiple coins consistently.

---

## Browser Accessibility Limitations

- Most SPV services are inaccessible from browsers because **raw socket access is disabled** for security reasons.  
- This prevents lightweight browser wallets (or PWAs) from connecting directly to SPV servers.  
- **CORS (Cross-Origin Resource Sharing)**, enforced by modern browsers, restricts decentralization possibilities. 
A user cannot access cross-domain services unless CORS headers are explicitly allowed by the service. 
- **Motivation for ROT**: provide a proxy layer that bridges browser-based clients to the SPV network in a secure and standardized way.

## Complexity for Wallet Users

- Wallet users are often unfamiliar with protocol details and should not have to make technical choices.  
- Too many options can become a stumbling block, leading to mistakes or abandonment.  
- **Motivation for ROT**: provide a **simplified, guided path** for wallet users while preserving decentralization under the hood.

---

## Shared Responsibility and Reputation

- Several teams have a **shared interest** in making CommunityCoins successful.  
- At the same time, each team can damage the **shared reputation** if it neglects its responsibilities or acts carelessly.  
- **Motivation for ROT**: design governance so that responsibilities are clear and failures are visible, preventing silent erosion of trust.

---

## Separation of Governance and Services

- Coin development and representation (teams, proxies) should remain distinct from technical service provision (SPV nodes).  
- Mixing these roles risks conflicts of interest and unclear accountability.  
- **Motivation for ROT**: define clear separation from the start.

---

## Transparency as a Principle

- Centralized systems often fail because misbehavior can be hidden or handled opaquely.  
- In decentralized systems, silent exclusion or arbitrary filtering undermines legitimacy.  
- **Motivation for ROT**: embed transparency in exclusion/warning processes, so community trust is maintained.

---

## Future Pressures

- Rising blockchain sizes will continue to make full nodes and SPV servers heavier.  
- Networks without lightweight, distributed trust layers risk centralization.  
- Currently, there is no clear economic incentive to maintain an SPV service other than **loyalty to the blockchain** itself. 
This creates an **unhealthy situation**: services may disappear, degrade, or be poorly maintained without notice.  A **governance framework** should be established that makes SPV reliability part of community responsibility, reducing reliance on goodwill alone.
- **Motivation for ROT**: act as a forward-looking response to these trends.
