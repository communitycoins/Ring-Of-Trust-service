# Design Considerations for ROT

This document collects the main design considerations for the **Ring of Trust (ROT)** architecture and the `rot.php` server.  
These choices are not final and may evolve as the project matures.

---

## 1. User Experience

- **Simplicity first**: Wallet users should not have to make protocol choices. Options often become obstacles.  
- **Default trust**: Users are pointed to LIGHT wallets by their community; the infrastructure should work without manual intervention.  

---

## 2. Governance & Responsibility

- **Team responsibility**: Each coin team is responsible for running ROT proxies, as they represent the coin’s identity.  
- **Shared reputation**: Several teams have a **shared interest** in making CommunityCoins successful. At the same time, each team has the power to **cause harm to the shared reputation** if it neglects its responsibility.  
- **CommunityCoins Manifest**: See the [Manifest](https://communitycoins.org/#manifest) for the guiding principles of cooperation and mutual responsibility between teams.  
- **Separation of concerns**: Governance (teams & proxies) is distinct from service provision (SPV nodes).  

---

## 3. Verifying SPV Servers

- Apart from comparing the reported **block height**, there is currently no way to independently verify the integrity of a single SPV server.  
- Since each SPV server has a full view of its blockchain, they should be able to **cross-verify one another**.  
- This verification is essential, because **lite-clients delegate trust** to SPV servers.  
- **Core principle of ROT**: create a system where SPV servers verify each other, reducing the blind trust users must place in any single server.

## 4. SPV Services

- **Strict conformance**: SPV services must adhere to protocol without deviation.  
- **Neutral role**: SPV services do not make governance decisions — they provide service only.  
- **Accountability**: Misbehaving services may be warned or excluded, but not silently. Transparency is essential.  
- **Scoped transparency**: Excessive transparency can also undermine trust if unintentional bugs are immediately exposed.  
  - Therefore, transparency should first be handled at the **team level**, allowing responsible teams to correct issues.  
  - This way, bugs that affect all teams can be fixed constructively without harming the shared reputation.

---

## 5. Transparency & Trust

- **Public accountability**: Warnings and exclusions should be visible to the community.  
- **Scoped visibility**: The design ensures that **SPV servers themselves are not directly visible to the public eye**.  
  - This protects them from malicious actors.  
  - It also allows them to communicate freely without the overhead of SSL certificates or external trust authorities.  
- **Openness at the right layer**: Transparency extends as far as the open-source repository and the proxy-level governance process, but **not to the point of public shaming of individual SPV servers**.  

---

## 6. Scope of Address and Transaction Types

- **Complexity trade-off**: Accommodating all address types and transaction verifications is overly complex and often unnecessary.  
- **Focus on legacy**: A survey shows that **p2pkh (legacy)** transaction types are still widely used, though declining due to SegWit and Bech32 adoption.  
- **Lite client simplification**: If lite clients only generate legacy addresses, SPV services only need to monitor transaction outputs of that type.  
- **Efficiency gain**: This significantly reduces the SPV processing load and keeps infrastructure lighter.

---

## 7. Future Directions

- Automating proxy discovery while keeping user control minimal.  
- Exploring how proxies can self-publish trust data without central coordination.  
- Defining metrics for “misbehavior” in SPV services.  
- Balancing strictness with inclusivity to avoid unnecessary forks in trust.  
