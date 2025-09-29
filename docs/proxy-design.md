# Proxy design - server side

Encryption, authentication
Both proxy-server and rot-server generate 
- An ID
    ```
    $ROT['ID']="";
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    for ($i=0;$i<32;$i++) {$ROT['ID'].=$alphabet[mt_rand(0,57)];}```
- A preferred day maintenance-time 
    
    ```$ROT['maintenanceTime']=(new DateTime())->getOffset();```
- A keypair for async encryption
    ```
    $pair=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_RSA,"private_key_bits"=>2048,]);
    openssl_pkey_export($pair,$ROT['private']);
    $ROT['public']=openssl_pkey_get_details($pair)['key'];
- Whenever they need to send encrypted messages these two functions are available
    ```
    function enc($message,$publicKey){
        global $ROT;
        $aesKey = openssl_random_pseudo_bytes(32);
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($message, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
        openssl_public_encrypt($aesKey, $encryptedKey, $publicKey, OPENSSL_PKCS1_PADDING);
        $package = base64_encode(json_encode([
            'key' => base64_encode($encryptedKey),
            'iv'  => base64_encode($iv),
            'data'=> base64_encode($ciphertext)
        ]));
        return $package;
    }
    function dec($package){
        global $ROT;
        $components = json_decode(base64_decode($package), true);
        $encryptedKey = base64_decode($package['key']);
        $iv           = base64_decode($package['iv']);
        $ciphertext   = base64_decode($package['data']);
        openssl_private_decrypt($encryptedKey, $aesKey, $ROT['privateKey']);
        $message = openssl_decrypt($ciphertext, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
    }```

### First handshake and rot-proof followed by state-pushing after each block received
    rot (=$url) sends an I-want-to-join request:$message="I-want-to-join&$tikker&".VERSION."&$socket&{$ROT['ID']}&$pgp_pem";
    proxy returns an audit message:     	Test with KEY|adittest|dummy
    rot sends a correct answer+current height	Test with KEY|audit|dummy
    proxy adds a rot-server-record(=file name)	{$ROOT}/{$tikker}/{$ROT['ID']}_{$url}_{$socket}_height_hash-suffix

### Upon client-request proxy selects appropriate rot-server
```
/proxy/data/
├── efl/
│   ├── abcd1234efgh5678efgh5678abcd1234_1.2.3.4:11014_mtime_3044567_gh56.json
│   ├── beef5678dead4321abcd5678feed4321_2.3.4.5:11014_mtime_3044567_gh56.json
│   └── ...
├── aur/
│   └── ...

    $root = "/proxy/data/efl/";
    $glob = glob("$root*");
    $latest = 0;
    $uptodate = [];
    
    foreach ($glob as $file) { 
        // select oldest mtime rot-server with highest block
        // if multiple highest blocks with different hash-suffixes ask client to retry
    }

