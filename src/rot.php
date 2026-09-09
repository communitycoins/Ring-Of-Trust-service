<?php
/* [CC-WALLET-007]
ROT 0.8.6 authenticated proxy registration and in-process control manager.
Base: - Derived from CC-WALLET-006 / ROT 0.8.5
Changes:
- [CC-WALLET-007] Create and retain one random rotid per ROT data directory
- Add an optional sanitized nickname and deterministic fallback label
- Discover proxy registration targets through the CommunityCoins bootstrap
- Run registration and lease renewal asynchronously through existing cURL multi support
- Authenticate private CCP1 status requests and return bounded chain checkpoints
- Persist signed proxy messages and acknowledge them only after local storage
- [CC-WALLET-006] Buffer partial requests and responses without blocking the single ROT loop
- Bound client count, per-client lifetime, request/response size and total socket memory
- Recalculate stream_select deadlines on every pass and enlarge the listen backlog
- Restrict the public legacy socket to wallet operations and disable remote diagnostics/stop
- Add connect and total timeouts to batched Core RPC calls
- [MULTI-COIN-017] Accept validated single-address P2PKH output metadata when legacy Core omits scriptPubKey.hex
- Preserve script-hex derivation as the preferred exact-output path
- Reject missing, multi-address, wrong-network and non-pubkeyhash address fallbacks
- [MULTI-COIN-016] Move atomic units into the ROT coin specification
- Convert verbose Core output values with the active coin's decimal precision
- Verify DEM mempool payments in 1,000,000 units without changing confirmed index values
- Preserve the existing eight-decimal behavior for EFL and the other configured coins
- [MULTI-COIN-015] Preserve getblockheader as the primary history timestamp RPC
- Fall back only unavailable or invalid timestamp reads to coin-neutral getblock
- Retain strict atomic history failure when neither RPC returns a valid block time
- [EFL-SLICE-046] Pass numeric verbose mode 1 to getrawtransaction for legacy Core compatibility
- Preserve exact mempool txid, recipient address and satoshi-amount verification
- [EFL-SLICE-044] Return confirmed IN and external OUT history for one atomic wallet address set
- Exclude wallet change and attach canonical block height and timestamp to every history event
- Bound and integrity-check address walks, spending transactions and history response size
- [EFL-SLICE-035] Require ROT_DATA_DIR separately from CORE_DATA_DIR in environment mode
- Use the configuration-file directory as ROT storage root in config-file mode
- Stop creating ROT runtime directories inside the Core blockchain directory
- [EFL-SLICE-034] Bind every full and rolling backup to the exact indexed height, hash and table tops
- Select the highest canonical rewind checkpoint instead of trusting file modification order
- Restore orphaned spend markers, PUB last-change heights and TXO list tails during rewind
- Stop indexing when an existing transaction id would be appended again
- Reject cyclic, invalid or duplicate outpoints while constructing wallet state
- [EFL-SLICE-033] Verify a known mempool txid against one exact legacy address and satoshi amount
- [EFL-SLICE-032] Accept larger raw transactions produced by multi-tier legacy-input selection
- [EFL-SLICE-031] Raise the atomic pubs snapshot from 32 to 51 addresses
- [EFL-SLICE-028] Extend pubs with optional known height and per-address change heights
- Return only changed address records while retaining the live chain checkpoint
- Avoid walking unchanged PUB-linked TXO lists and leave delta aggregation to the wallet
- [EFL-SLICE-026] Add bounded send and txstatus client commands.
- Pass raw transactions to Core without constructing or signing them in ROT.
- Return immediate structured Core acceptance, rejection and technical outcomes.
- Calculate the transaction id independently and expose Core and ROT timings.
- [EFL-SLICE-015] Add a generic pubs command for one consistent multi-address snapshot.
- Return aggregate balance, per-address UTXOs and the indexed chain checkpoint as JSON.
- Reject invalid-network, duplicate, empty and oversized address collections.
- Keep the existing diagnostic commands unchanged and use PHP 7.3-compatible callbacks.
*/

/* Its purpose is to build a full legacy blockindex
 
  Goal    : present memory-index for publickey hashes, (un)spend outputs and transaction id's
  Purpose : Engine for SPV-services that serve legacy-only light clients P2PKH-addresses
  Model  : - Reads transaction-blocks streight from blocks/blk*.dat
           - Due to core's buffered IO the top cannot be reached. Switch to RPC
           - Tip reorganisations are accommodated through rewind (backup/recover)
           - Parses binary blocks
           - skips non-relevant transactions (coinbase)
           - skips non-relevant inputs (segwit)
           - skips non-relevant output (all except P2PKH)
  Data   : - three indexes (TX, PUB, TXO)
           - Keep all indexes in memory
           - Add block-number to PUB to mark changes
           - TXO's directly accessable by TX- and PUB records;
           - TX-outputs are sequentially linked (no pointer);
           - PUB-TXOs are linked through linked list; Maintain last TXO for fast addition

  [index](size)    
  TX_table-bucket(44):  [0]txid(32) + [1]blocknr/lastchange(4) + [2]txo-pointer(4) + [3]collision-linked-list(4)
  PUB_table-bucket(36): [0]scripthash(20) + [1]blocknr/lastchange(4) + [2]first txo(4) + [3]last txo(4) + [4]collision-linked-list(4)
  TXO_table_bucket(28): [0]txin(4) + [1]nout(4) + [2]value(8) + [3]scripthash(4) + [4]txout/spend(4) + [5]scripthash-txo-linkedlist(4) 
  
  +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
   The main loop starts at extractBlocksFromStream(). This is a "generator" and retrieves blocks sequentially but un-ordered from blocks/blk*.dat   
   since blocks don't occur serially in blk*.dat:
   - class BlockIndex first fetches a serial map of blocks.
   - extractBlocksFromStream loads blocks encountered serially but stores those "out of sync" in $blockbuffer[]
   - Block 0 is skipped
   
   **TXdata** all legacy non-coinbase transactions serialized through parsing of blk*.dat
   **TXidx**  For each block a pointer to the first transaction in **TXdata** for that block plus the amount of relevant transactions in that block
   **BLKidx** for each block a 6-byte pointer into blk*.dat

  +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
*/   

$environmentError='';
$options = getopt('c:h', ['config:', 'help']);
if (isset($options['h']) || isset($options['help'])) {die("Usage: php rot.php --config=/path/to/rot.conf\n");}
$configFile = $options['c'] ?? $options['config'] ?? getenv('ROT_CONFIG') ?? null;
$rotNicknameInput=getenv('ROT_NICKNAME');
$proxySeedsInput=getenv('ROT_PROXY_SEEDS');
$proxyTargetInput=getenv('ROT_PROXY_TARGET');
if ($configFile) {
    $resolvedConfigFile=realpath($configFile);
    if ($resolvedConfigFile===false || !is_file($resolvedConfigFile)) {die("Error: config not found: $configFile\n");}
    $parts = explode('|', trim(file_get_contents($resolvedConfigFile)));
    if (count($parts) !== 7) {die("Error: invalid config format (expected 7 fields)\n");}
    [$tikker,$rpchost,$user,$ww,$rpcport,$socket,$datadir] = $parts;
    $rotDataDir=dirname($resolvedConfigFile);
    if ($datadir && !is_dir($datadir)) {die("$datadir does not exist;\n");}
} else {
    $environmentError='';
    $rpchost=getenv('CORE_RPC_HOST');
    $rpcport=getenv('CORE_RPC_PORT');
    $user=getenv('CORE_RPC_USER');
    $ww=getenv('CORE_RPC_PASSWORD');
    $datadir=getenv('CORE_DATA_DIR');
    $rotDataDir=getenv('ROT_DATA_DIR');
    $socket=getenv('ROT_LISTEN_ADDR');
    $tikker=getenv('ROT_COIN_TIKKER');
    if (!$rpchost) {$environmentError.="CORE_RPC_HOST required;\n";}
    if (!$rpcport) {$environmentError.="CORE_RPC_PORT required;\n";}
    if (!$user) {$environmentError.="CORE_RPC_USER required;\n";}
    if (!$ww) {$environmentError.="CORE_RPC_PASSWORD required;\n";}
    if (!$datadir) {$environmentError.="CORE_DATA_DIR required;\n";}
    if (!$rotDataDir) {$environmentError.="ROT_DATA_DIR required;\n";}
    if (!$socket) {$environmentError.="ROT_LISTEN_ADDR required;\n";}
    if (!$tikker) {$environmentError.="ROT_COIN_TIKKER required;\n";}
    if ($datadir && !is_dir($datadir)) {$environmentError.="$datadir does not exist;\n";}
    if ($rotDataDir && !is_dir($rotDataDir)) {$environmentError.="$rotDataDir does not exist;\n";}
    if ($environmentError!=''){die($environmentError);}
}

$resolvedCoreDataDir=realpath($datadir);
$resolvedRotDataDir=realpath($rotDataDir);
if ($resolvedCoreDataDir===false) {die("Invalid Core data directory\n");}
if ($resolvedRotDataDir===false) {die("Invalid ROT data directory\n");}
$corePath=rtrim($resolvedCoreDataDir,"/\\");
$rotPath=rtrim($resolvedRotDataDir,"/\\");
if ($corePath==='') {die("Core data directory cannot be the filesystem root\n");}
if ($rotPath==='') {die("ROT data directory cannot be the filesystem root\n");}
$corePrefix=$corePath.DIRECTORY_SEPARATOR;
$rotPrefix=$rotPath.DIRECTORY_SEPARATOR;
if ($corePath===$rotPath || strpos($rotPrefix,$corePrefix)===0 || strpos($corePrefix,$rotPrefix)===0) {
    die("CORE_DATA_DIR and ROT_DATA_DIR must be separate directory trees\n");
}
$datadir=$corePath;
$rotDataDir=$rotPath;
define ("VERSION","0.8.6");
define ("MAX_PUBS",51);
define ("MAX_HISTORY_EVENTS",2000);
define ("MAX_HISTORY_WALLET_OUTPUTS",4000);
define ("MAX_RAW_TRANSACTION_HEX",65000);
define ("MAX_SOCKET_CLIENTS",128);
define ("MAX_SOCKET_ACCEPTS_PER_PASS",32);
define ("MAX_SOCKET_REQUEST_BYTES",65536);
define ("MAX_SOCKET_RESPONSE_BYTES",2097152);
define ("MAX_SOCKET_BUFFER_BYTES",33554432);
define ("SOCKET_READ_TIMEOUT",5.0);
define ("SOCKET_WRITE_TIMEOUT",10.0);
define ("SOCKET_BACKLOG",256);
define ("REGISTRATION_PROTOCOL",1);
define ("REGISTRATION_INTERVAL",300);
define ("REGISTRATION_JITTER",30);
define ("REGISTRATION_CONNECT_TIMEOUT",2);
define ("REGISTRATION_TOTAL_TIMEOUT",15);
define ("REGISTRATION_RESPONSE_BYTES",65536);
define ("REGISTRATION_TIMESTAMP_TOLERANCE",120);
define ("REGISTRATION_DIRECTORY_LIFETIME",86400);
define ("REGISTRATION_DIRECTORY_RETENTION",604800);
define ("REGISTRATION_PUMP_INTERVAL",0.1);
define ("REGISTRATION_DEFAULT_SEED","https://wallet.communitycoins.org/proxy.php");
define("ROOT",$rotDataDir."/");
define("Q",ROOT."Q");
define("A",ROOT."A");
define("DATA",ROOT."data/");
if (!file_exists(Q)) {mkdir(Q);}
if (!file_exists(A)) {mkdir(A);}
if (!file_exists(ROOT."data")) {mkdir(ROOT."data");}
if (file_exists(ROOT."DEBUG")) {define("DEBUG",true);echo "debug mode\n";} else {define("DEBUG",false);}

$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
$versionsBytes = ["LTC" => 48,"BTC" => 0x00,"CDN" => 28,"DEM" => 53,"EFL" => 48,"AUR" => 23,"PAK" => 0x00,"SLG" => 0x00,"RUBTC" => 0x00,
                  "FJC" => 0x00,"BOLI" => 0x00,"CESC" => 0x00];
$coinUnits = ["LTC" => 100000000,"BTC" => 100000000,"CDN" => 100000000,"DEM" => 1000000,"EFL" => 100000000,"AUR" => 100000000,
              "PAK" => 100000000,"SLG" => 100000000,"RUBTC" => 100000000,"FJC" => 100000000,"BOLI" => 100000000,"CESC" => 100000000];
function now(){return date('d-m-Y H:i');}
function L($what){$extra="";if (($what!=".")&&(substr($what,-1)!="\n")){$extra="\n";}file_put_contents(ROOT."rot.log",$what.$extra,FILE_APPEND);echo $what.$extra;}
if (!function_exists('array_key_last')) {function array_key_last(array $array) {if (empty($array)) {return null;}return key(array_slice($array, -1, 1, true));}}

$rotId=loadRotId();
$rotNickname=sanitizeRotNickname($rotNicknameInput,$rotId);
$registrationManager=initializeRegistrationManager($proxySeedsInput,$proxyTargetInput);

$start=time();
@unlink(DATA."TXidx");
@unlink(DATA."BLKidx");
file_put_contents(ROOT."pid",getmypid());
register_shutdown_function(function(){
    global $registrationManager;
    if (isset($registrationManager['active']['handle'])) {@curl_multi_remove_handle($registrationManager['multi'],$registrationManager['active']['handle']);@curl_close($registrationManager['active']['handle']);}
    if (isset($registrationManager['multi'])) {@curl_multi_close($registrationManager['multi']);}
    @unlink(ROOT."pid");
});

$rpchost='127.0.0.1';
if (strpos($rpcport,":")>0) {list($rpchost,$rpcport)=explode(":",$rpcport);}
$tikker=strtoupper($tikker);
if (!isset($versionsBytes[$tikker]) || !isset($coinUnits[$tikker]) || !preg_match('/^10*$/',(string)$coinUnits[$tikker])) {die("Unsupported coin specification\n");}
$versionByte=$versionsBytes[$tikker];
$unitsPerCoin=$coinUnits[$tikker];
$coinDecimals=strlen((string)$unitsPerCoin)-1;
define ("SOCKET",$socket);

L("==== Start ".now()." Version ".VERSION."  $tikker ====\n\n");

if (substr($datadir,-1)!="/") {$datadir.="/";}
if (file_exists("{$datadir}blocks")) {$datadir.="blocks/";}
$blockFiles=glob("{$datadir}blk*.dat");
if (count($blockFiles)==0) {
    L("No block-data found: {$datadir}blk*.dat\n");
    die();
} else {
    $handle=fopen($blockFiles[0],"r");
    $magic=fread($handle,4);
    fclose($handle);
    define ("MAGIC",$magic);
}

$rpc = [
    'user' => $user,
    'pass' => $ww,
    'host' => $rpchost,
    'port' => $rpcport
];
$RPC=new JsonRpcClient($rpc);   // Though minimalistic, we need core

$BLOCKINDEX=new BlockIndex();   // sequential list of blockhash/height pairs provided by core

// hashtable constants and definitions
define("LONG",4);
define("P",0);
define("SIZE",1);
define("TOP",2);
define("KEY",3);
define("FORMAT_PACK",4);
define("FORMAT_UNPACK",5);
define("RECORDSIZE",6);
define("NAME",7);
define("INCREMENT",8);

$raceToTheTop=true;$raceStatus=$raceToTheTop; // Switches 'off' when top is reached, but turned on after tip-reorganisation-recovery
$fullBackup=false; // Switches 'on' when top is reached
$lastBlockHash=""; // Last block indexed
$blockbuffer=[];   // non-sequential encountered blocks
$max_buffer=$orphan=$blkvalid=$skipped=$relevant=0;
$TXidx="";$TXdata="";$TX_sum=0;$BLKidx="";

$recovery=false;
if (file_exists(DATA."AUX")){ // Recover...
    L("Recovering ...");
    if (recover(true)) {
        $height=$parseContext['height']+1; // Next height to look for
        $lastBlockHash=$parseContext['hash'];
        $recovery=true;
    } else {
        L("Full backup is incomplete or does not match core's vision \nRestarting ...\n");
        $parseContext['currentFile']='';
        $parseContext['offset']=0;
        $parseContext['hash']='';
        $parseContext['height']=0;
    }
}
if (!$recovery){
    $parseContext['currentFile']='';
    $parseContext['offset']=0;
    $TX_table['name']           ="TX";
    $TX_table['N']          =10000000;   // hash-positions (4-bytes a piece) -> 40Mb
    $TX_table['increment']              =100000000;  // increment empty space to prevent frequent reallocations (100 Mb)                                        
    if (($tikker=='LTC')||($tikker=='BTC')||($tikker=='DOGE')) {$multiplier=10;} else {$multiplier=1;}   // hash-positions -> 400Mb
    $bucketPercentage=1; // reduce memory based on history
    if ($tikker=='EFL') {
        $bucketPercentage=0.2; 
    } elseif ($tikker=='CDN') {
        $bucketPercentage=0.5;
    } elseif ($tikker=='AUR') {
        $bucketPercentage=1;
    } elseif ($tikker=='CESC') {
        $bucketPercentage=0.3;
    }
    $TX_table['N']*=$multiplier;
    $TX_table['increment']*=$multiplier*$bucketPercentage;  
    
    $TX_table['hash'][P]=0;
    $TX_table['hash'][FORMAT_PACK]="V";
    $TX_table['hash'][FORMAT_UNPACK]="V";
    $TX_table['hash'][RECORDSIZE]=LONG;
    $TX_table['hash'][SIZE]=$TX_table['N']*$TX_table['hash'][RECORDSIZE];
    $TX_table['hash'][TOP]=$TX_table['N']; // De hoogste GEVULDE index
    $TX_table['hash'][NAME]='TX-hash';
    
    $TX_table['bucket'][P]=0;
    $TX_table['bucket'][INCREMENT]=$TX_table['increment'];
    $TX_table['bucket'][FORMAT_PACK]="a32V3";  
    $TX_table['bucket'][FORMAT_UNPACK]="a32tx/Vblock/Vtxo/Vnext"; 
    $TX_table['bucket'][RECORDSIZE]=32+3*LONG;
    $TX_table['bucket'][SIZE]=$TX_table['bucket'][INCREMENT]*$bucketPercentage;
    $TX_table['bucket'][TOP]=0;
    $TX_table['bucket'][NAME]='TX-bucket';
    $TX_table['hash'][KEY]=ftok(__FILE__, 'A');
    $TX_table['bucket'][KEY]=ftok(__FILE__, 'B');
    hashtable_initialize($TX_table['hash']);
    hashtable_initialize($TX_table['bucket']);
    
    $PUB_table['name']="PUB";
    $PUB_table['hash'][P]=0;
    $PUB_table['hash'][FORMAT_PACK]="V";
    $PUB_table['hash'][FORMAT_UNPACK]="V";
    $PUB_table['hash'][RECORDSIZE]=LONG;
    $PUB_table['hash'][SIZE]=$TX_table['N']*LONG;
    $PUB_table['hash'][TOP]=$TX_table['N']; // De hoogste GEVULDE index
    $PUB_table['hash'][NAME]='PUB-hash';
    $PUB_table['bucket'][P]=0;
    $PUB_table['bucket'][INCREMENT]=$TX_table['increment'];
    $PUB_table['bucket'][FORMAT_PACK]="a20V4"; 
    $PUB_table['bucket'][FORMAT_UNPACK]="a20hash/Vblock/Vfirst/Vlast/Vnext"; // Should I maintain balances too? > richlist
    $PUB_table['bucket'][RECORDSIZE]=20+4*LONG;
    $PUB_table['bucket'][SIZE]=$PUB_table['bucket'][INCREMENT]*$bucketPercentage;
    $PUB_table['bucket'][TOP]=0;
    $PUB_table['bucket'][NAME]='PUB-bucket';
    $PUB_table['hash'][KEY]=ftok(__FILE__, 'C');
    $PUB_table['bucket'][KEY]=ftok(__FILE__, 'D');
    hashtable_initialize($PUB_table['hash']);
    hashtable_initialize($PUB_table['bucket']);
    
    $TXO_table['name']="TXO";
    $TXO_table['bucket'][P]=0;
    $TXO_table['bucket'][INCREMENT]=$TX_table['increment'];
    $TXO_table['bucket'][FORMAT_PACK]="V2PV3"; 
    $TXO_table['bucket'][FORMAT_UNPACK]="Vtxin/Vnout/Pvalue/Vhash/Vtxout/Vnext"; 
    $TXO_table['bucket'][RECORDSIZE]=28;
    $TXO_table['bucket'][SIZE]=$TXO_table['bucket'][INCREMENT]*$bucketPercentage;
    $TXO_table['bucket'][TOP]=0;
    $TXO_table['bucket'][NAME]='TXO-bucket';
    $TXO_table['bucket'][KEY]=ftok(__FILE__, 'E');
    hashtable_initialize($TXO_table['bucket']);

    $height=1;
}

$parser = new BlockParser();
L("Race to the top ...\n");
foreach (extractBlocksFromStream() as $entry) {
    /* Two situations are inter-twined:
       raceToTheTop=true : truth about the chain block-sequence is in hashMap. $entry['id'] is the block height; No fork-blocks will appear
                           Blocks received out of sequence are stored in $blockbuffer
       raceToTheTop=false: we are at the top. We got the block streight through RPC. So this is the new truth.
                           Just see if it convenes with the old truth, otherwise rewind.
    */
    if ($raceStatus!=$raceToTheTop){
        L("Top reached at height ".($height-1)."\n");
        if (!$fullBackup) {
            backup(true,$height-1,$lastBlockHash);
            $fullBackup=true;
        }
        $raceStatus=$raceToTheTop;
        $blockbuffer=[];
        file_put_contents(DATA."TXidx",$TXidx,FILE_APPEND); // once at the top these will no longer be amended; Future explorers might be interested
        file_put_contents(DATA."TXdata",$TXdata,FILE_APPEND);
        file_put_contents(DATA."BLKidx",$BLKidx,FILE_APPEND);
        $TXidx="";$TXdata="";$BLKidx="";
        $passed=time()-$start;
        $len_buffer=count($blockbuffer);
        L("blk.dat:{$entry['fileNumber']} height:$height seconds:$passed buffer_max:$max_buffer buffer:$len_buffer orphans:$orphan valid:$blkvalid skipped:$skipped relevant:$relevant\n");
    }
    
    if ($raceToTheTop==false) { // Got block through RPC but anticipate reorganisations
        L("New block:".$entry['hash']."; height $height\n");
        if ($entry['prevHash']!=$lastBlockHash) { // There we have one
            L("We are on a orphaned block at height $height\n");
            recover();
            $height=$parseContext['height'];
            while (true) {
                $lastBlockHash = $RPC->call('getblockhash', [$height]);
                if ($lastBlockHash!==null) {break;}
            }
            $height++;
            continue;
        } elseif (file_exists(ROOT."recover")) {
            L("recovery test: REWIND at $height\n");
            recover();
            $height=$parseContext['height'];
            while (true) {
                $lastBlockHash = $RPC->call('getblockhash', [$height]);
                if ($lastBlockHash!==null) {break;}
            }
            $height++;
            @unlink(ROOT."recover");
            continue;
        }
    } elseif ($entry['id']>$height) {
        $blockbuffer[]=$entry;
        if (count($blockbuffer)>$max_buffer){$max_buffer=count($blockbuffer);            }
        foreach ($blockbuffer as $n => $entry) {
            if ($entry['id']<$height-$BLOCKINDEX->maxReorgDepth) {unset($blockbuffer[$n]);}  // IS THIS STILL NECESSARY?
            if ($entry['id']==$height) {
                unset($blockbuffer[$n]);
                break;
            }
        }
    }
    while ($entry['id']==$height) {
        if (($raceToTheTop && ($height%100000)==1)) {
            if ($height>1) {
                $passed=time()-$start;
                $len_buffer=count($blockbuffer);
                L("blk.dat:{$entry['fileNumber']} height:$height seconds:$passed buffer_max:$max_buffer buffer:$len_buffer orphans:$orphan valid:$blkvalid skipped:$skipped relevant:$relevant\n");
                if (!$fullBackup){
                    file_put_contents(DATA."TXdata",$TXdata,FILE_APPEND); // will store all transactions processed
                    file_put_contents(DATA."TXidx",$TXidx,FILE_APPEND);   // per block, pointer to first transaction
                    file_put_contents(DATA."BLKidx",$BLKidx,FILE_APPEND); // for each block a 6-byte pointer into blk*.dat
                    $TXidx="";$TXdata="";$BLKidx="";
                }
            }
        }    
        
        $lastBlockHash=$entry['hash'];
        if (($height>=$BLOCKINDEX->backupHeight) && (($height%$BLOCKINDEX->maxReorgDepth)==0)) {
            backup(false,$height-1,$entry['prevHash']); // The arriving block is not indexed yet
        }
        $parsed = $parser->getBlock($entry['raw']);
/// fresh parsed block        
        
        $skipped+=$parsed['skipped'];
        $relevant+=count($parsed['transactions']);
        if (!$fullBackup){
            $TXidx.=pack('Vv',$TX_sum,count($parsed['transactions']));  // All relevant tx's per block (can be 0)
            $BLKidx.=pack('vV',$entry['fileNumber'],$entry['offset']);  // pointer to block in blk*.dat (to avoid rpc getrawtransaction/txindex=1)
        }
        foreach ($parsed['transactions'] as $tx){
            $txHash=hex2bin($tx['txid']);
            list($existingTxID,$existingTx)=find($TX_table,$txHash);
            if ($existingTxID!==false && isset($existingTx[0]) && $existingTx[0]===$txHash) {
                L("Duplicate transaction id {$tx['txid']} at height $height; index rebuild required\n");
                die("Duplicate transaction index\n");
            }
            $txID=hashtable_add_TX($TX_table,[$txHash,$height,$TXO_table['bucket'][TOP]+1,0]);
            foreach ($tx['outputs'] as $output) {
                // output(32): [0]n(4) [1]value/amount(8) [2]pubkeyhash(20)
                // pub(36):    [0]scripthash(20) [1]blocknr/lastchange(4) [2]first-txo(4) [3]last-txo(4) [4]next hash%-collision(4)
                $next_txoID=$TXO_table['bucket'][TOP]+1; //
                [$pubID,$previous_last_txo]=hashtable_add_PUB($PUB_table,[$output[2],$height,$next_txoID,$next_txoID,0]);

                // txo(28):    [0]txin(4) [1]nout(4) [2]value(8) [3]scripthash(4) [4]txout(4) [5]next scripthash txo(4)
                $txoID=flattable_append($TXO_table,[$txID,$output[0],$output[1],$pubID,0,0]);
                if ($previous_last_txo!=$txoID) { //Existing PUB; correct linked list of TXO's
                    if ($previous_last_txo==0) {
                        L("Append txo, but previous-last is zero");
                    }
                    $content=hashtable_read($TXO_table['bucket'],$previous_last_txo);
                    $content[5]=$txoID;
                    hashtable_write($TXO_table['bucket'],$previous_last_txo,$content);  // All outputs to a scrypthash are inter-linked
                }   // else hashtable_add_PUB didn't actually add PUB but updated the last-txo
            }

            if (!$tx['is_segwit']){ // Spend. Don't need to service inputs from segwit-tx; Cannot be spend by cc-wallets; Legacy wallets don't see them anyway
                foreach ($tx['inputs'] as $input) {
                    $prev_tx=strrev($input[0]);$prev_vout=$input[1];
                    [$index,$record]=find($TX_table,$prev_tx);
                    if ($index!==false){
                        // [0]txid(32) + [1]blocknr/lastchange(4) + [2]txo-pointer(4) + [3]next-hash-collision(4)
                        // txo[0]:9 txo[1]:0 txo[2]:2500000000 txo[3]:12 txo[4]:0 txo[5]:0
                        // [0]txin(4) - [1]nout(4) - [2]value(8) - [3]scripthash(4) - [4]txout/spend(4) - [5]next scripthash txo(4) 
                        $txo=hashtable_read($TXO_table['bucket'],$record[2]);
                        $i=0;
                        while (($txo[0]==$index)&&($txo[1]<$prev_vout)){
                            $i++;
                            $txo=hashtable_read($TXO_table['bucket'],$record[2]+$i);
                        }
                        while (($txo[0]==$index)&&($txo[1]<$prev_vout)){
                            $i++;
                            $txo=hashtable_read($TXO_table['bucket'],$record[2]+$i);
                        }
                        if (($txo[0]==$index)&&($txo[1]==$prev_vout)){ // got him; mark as spend
                            $txo[4]=$txID;
                            hashtable_write($TXO_table['bucket'],$record[2]+$i,$txo);
                            $pubcontent=hashtable_read($PUB_table['bucket'],$txo[3]); // mark last change with pubkeyhash
                            $pubcontent[1]=$height;
                            hashtable_write($PUB_table['bucket'],$txo[3],$pubcontent);                    
                        } 
                    }
                }
            }
            $TX_sum++;
        }         
        $height++;
        if ($raceToTheTop) {
            foreach ($blockbuffer as $n => $entry) {
                if ($entry['id']<$height-$BLOCKINDEX->maxReorgDepth) {unset($blockbuffer[$n]);}                
                if ($entry['id']==$height) {
                    unset($blockbuffer[$n]);
                    break;
                }
            }
        }
    }
}
// This will not be reached
echo "If you are in doubt just confess...";

function writeAtomicText($path,$content,$mode=0600) {
    try {
        $suffix=bin2hex(random_bytes(8));
    } catch (\Exception $exception) {
        return false;
    }
    $temporary=$path.'.tmp.'.getmypid().'.'.$suffix;
    $handle=@fopen($temporary,'x');
    if ($handle===false) {return false;}
    $length=strlen($content);
    $offset=0;
    while ($offset<$length) {
        $written=@fwrite($handle,substr($content,$offset));
        if ($written===false || $written===0) {fclose($handle);@unlink($temporary);return false;}
        $offset+=$written;
    }
    if (!fflush($handle)) {fclose($handle);@unlink($temporary);return false;}
    fclose($handle);
    @chmod($temporary,$mode);
    if (!@rename($temporary,$path)) {@unlink($temporary);return false;}
    return true;
}

function loadRotId() {
    $path=ROOT.'rotid';
    if (file_exists($path)) {
        $value=trim((string)@file_get_contents($path));
        if (!preg_match('/^[0-9a-f]{32}$/',$value)) {die("Invalid ROT identity file: $path\n");}
        return $value;
    }
    try {
        $value=bin2hex(random_bytes(16));
    } catch (\Exception $exception) {
        die("Cannot generate ROT identity\n");
    }
    $handle=@fopen($path,'x');
    if ($handle===false) {
        $existing=trim((string)@file_get_contents($path));
        if (!preg_match('/^[0-9a-f]{32}$/',$existing)) {die("Cannot create ROT identity: $path\n");}
        return $existing;
    }
    $content=$value."\n";
    $written=@fwrite($handle,$content);
    $flushed=$written===strlen($content) && fflush($handle);
    fclose($handle);
    @chmod($path,0600);
    if (!$flushed) {die("Cannot store ROT identity: $path\n");}
    return $value;
}

function sanitizeRotNickname($value,$rotId) {
    $nickname=is_string($value)?preg_replace('/[^A-Za-z0-9._-]/','',$value):'';
    $nickname=substr((string)$nickname,0,32);
    return $nickname===''?substr($rotId,-6):$nickname;
}

function normalizedProxyUrl($value) {
    if (!is_string($value) || strlen($value)>255) {return false;}
    $parts=parse_url(trim($value));
    if (!is_array($parts) || !isset($parts['scheme'],$parts['host']) || strtolower($parts['scheme'])!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {return false;}
    $host=strtolower($parts['host']);
    if (!preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$host)) {return false;}
    $port=isset($parts['port'])?(int)$parts['port']:443;
    if ($port!==443) {return false;}
    $path=isset($parts['path'])?$parts['path']:'/proxy.php';
    if ($path!=='/proxy.php') {return false;}
    return 'https://'.$host.'/proxy.php';
}

function registrationSeeds($value) {
    $source=is_string($value) && trim($value)!==''?$value:REGISTRATION_DEFAULT_SEED;
    $result=[];
    foreach (explode(',',$source) as $candidate) {
        $url=normalizedProxyUrl($candidate);
        if ($url!==false) {$result[$url]=$url;}
        if (count($result)>=10) {break;}
    }
    if (count($result)===0) {die("No valid ROT proxy seed configured\n");}
    return array_values($result);
}

function loadRegistrationState() {
    $path=ROOT.'proxy-registrations.json';
    if (!file_exists($path)) {return ['version'=>1,'directory'=>[],'directoryFetchedAt'=>0,'directoryExpiresAt'=>0,'proxies'=>[]];}
    $raw=@file_get_contents($path);
    $decoded=is_string($raw)?json_decode($raw,true):null;
    if (!is_array($decoded) || !isset($decoded['version']) || $decoded['version']!==1) {
        $preserved=$path.'.corrupt.'.time();
        @rename($path,$preserved);
        L("Invalid proxy registration state preserved as ".basename($preserved));
        return ['version'=>1,'directory'=>[],'directoryFetchedAt'=>0,'directoryExpiresAt'=>0,'proxies'=>[]];
    }
    if (!isset($decoded['directory']) || !is_array($decoded['directory'])) {$decoded['directory']=[];}
    if (!isset($decoded['proxies']) || !is_array($decoded['proxies'])) {$decoded['proxies']=[];}
    $decoded['directoryFetchedAt']=isset($decoded['directoryFetchedAt'])?(int)$decoded['directoryFetchedAt']:0;
    $decoded['directoryExpiresAt']=isset($decoded['directoryExpiresAt'])?(int)$decoded['directoryExpiresAt']:0;
    return $decoded;
}

function saveRegistrationState() {
    global $registrationManager;

    $encoded=json_encode($registrationManager['state'],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($encoded===false || !writeAtomicText(ROOT.'proxy-registrations.json',$encoded."\n",0600)) {
        L("Cannot persist proxy registration state");
        return false;
    }
    return true;
}

function initializeRegistrationManager($seedInput,$targetInput) {
    if (!function_exists('curl_multi_init')) {die("PHP cURL multi support required\n");}
    $target=3;
    if (is_string($targetInput) && $targetInput!=='') {
        if (!preg_match('/^[1-9][0-9]*$/',$targetInput) || (int)$targetInput<1 || (int)$targetInput>10) {die("ROT_PROXY_TARGET must be 1 through 10\n");}
        $target=(int)$targetInput;
    }
    $asynchronousDns=defined('CURL_VERSION_ASYNCHDNS') && (curl_version()['features'] & CURL_VERSION_ASYNCHDNS)!==0;
    if (!$asynchronousDns) {L("Warning: libcurl does not advertise asynchronous DNS");}
    return [
        'multi'=>curl_multi_init(),
        'active'=>null,
        'buffers'=>[],
        'seeds'=>registrationSeeds($seedInput),
        'seedIndex'=>0,
        'target'=>$target,
        'state'=>loadRegistrationState(),
        'directoryRetryAt'=>0,
        'asynchronousDns'=>$asynchronousDns
    ];
}

function registrationJitter($seconds=REGISTRATION_JITTER) {
    try {
        return random_int(-$seconds,$seconds);
    } catch (\Exception $exception) {
        return mt_rand(-$seconds,$seconds);
    }
}

function validProxyId($value) {
    return is_string($value) && preg_match('/^[A-Za-z0-9._-]{3,64}$/',$value)?$value:false;
}

function validateProxyDirectory($decoded) {
    if (!is_array($decoded) || !isset($decoded['ok'],$decoded['protocol'],$decoded['generatedAt'],$decoded['expiresAt'],$decoded['proxies']) || $decoded['ok']!==true || $decoded['protocol']!==1 || !is_int($decoded['generatedAt']) || !is_int($decoded['expiresAt']) || !is_array($decoded['proxies']) || count($decoded['proxies'])<1 || count($decoded['proxies'])>10) {return false;}
    if ($decoded['expiresAt']<time()-REGISTRATION_TIMESTAMP_TOLERANCE || abs(time()-$decoded['generatedAt'])>REGISTRATION_TIMESTAMP_TOLERANCE+300) {return false;}
    $result=[];
    foreach ($decoded['proxies'] as $entry) {
        if (!is_array($entry) || !isset($entry['proxyId'],$entry['proxyUrl'],$entry['acceptsRegistrations'],$entry['acceptedCoins']) || $entry['acceptsRegistrations']!==true || !is_array($entry['acceptedCoins'])) {continue;}
        $proxyId=validProxyId($entry['proxyId']);
        $url=normalizedProxyUrl($entry['proxyUrl']);
        if ($proxyId===false || $url===false) {continue;}
        $coins=[];
        foreach ($entry['acceptedCoins'] as $coin) {if (is_string($coin) && preg_match('/^[A-Z0-9]{2,10}$/',$coin)) {$coins[$coin]=$coin;}}
        if (count($coins)===0) {continue;}
        $result[$proxyId]=['proxyId'=>$proxyId,'proxyUrl'=>$url,'acceptedCoins'=>array_values($coins),'observedAt'=>isset($entry['observedAt'])?(int)$entry['observedAt']:0];
    }
    return count($result)>0?array_values($result):false;
}

function selectedProxyIds() {
    global $registrationManager,$rotId,$tikker;

    $ranked=[];
    foreach ($registrationManager['state']['directory'] as $entry) {
        if (!is_array($entry) || !isset($entry['proxyId'],$entry['proxyUrl'],$entry['acceptedCoins']) || !in_array($tikker,$entry['acceptedCoins'],true)) {continue;}
        $ranked[]=['proxyId'=>$entry['proxyId'],'score'=>hash('sha256',$rotId.'|'.$entry['proxyId'])];
    }
    usort($ranked,function($left,$right) {return strcmp($left['score'],$right['score']);});
    $selected=[];
    foreach (array_slice($ranked,0,$registrationManager['target']) as $entry) {$selected[$entry['proxyId']]=true;}
    return $selected;
}

function mergeRegistrationDirectory(array $directory,$generatedAt,$expiresAt) {
    global $registrationManager;

    $registrationManager['state']['directory']=$directory;
    $registrationManager['state']['directoryFetchedAt']=$generatedAt;
    $registrationManager['state']['directoryExpiresAt']=$expiresAt;
    $selected=selectedProxyIds();
    $present=[];
    foreach ($directory as $entry) {
        $proxyId=$entry['proxyId'];
        $present[$proxyId]=true;
        $previous=isset($registrationManager['state']['proxies'][$proxyId]) && is_array($registrationManager['state']['proxies'][$proxyId])?$registrationManager['state']['proxies'][$proxyId]:[];
        $registrationManager['state']['proxies'][$proxyId]=array_merge($previous,[
            'proxyId'=>$proxyId,
            'proxyUrl'=>$entry['proxyUrl'],
            'selected'=>isset($selected[$proxyId]),
            'nextDueAt'=>isset($previous['nextDueAt'])?(int)$previous['nextDueAt']:time()
        ]);
    }
    foreach (array_keys($registrationManager['state']['proxies']) as $proxyId) {
        if (!isset($present[$proxyId])) {unset($registrationManager['state']['proxies'][$proxyId]);continue;}
        $registrationManager['state']['proxies'][$proxyId]['selected']=isset($selected[$proxyId]);
    }
    saveRegistrationState();
}

function registrationWriteChunk($handle,$data) {
    global $registrationManager;

    $id=(int)$handle;
    if (!isset($registrationManager['buffers'][$id])) {$registrationManager['buffers'][$id]='';}
    if (strlen($registrationManager['buffers'][$id])+strlen($data)>REGISTRATION_RESPONSE_BYTES) {return 0;}
    $registrationManager['buffers'][$id].=$data;
    return strlen($data);
}

function startRegistrationTransfer($type,$url,array $payload,array $meta=[]) {
    global $registrationManager;

    if ($registrationManager['active']!==null) {return false;}
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES);
    if ($json===false) {return false;}
    $handle=curl_init($url);
    if ($handle===false) {return false;}
    curl_setopt_array($handle,[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_WRITEFUNCTION=>'registrationWriteChunk',
        CURLOPT_CONNECTTIMEOUT=>REGISTRATION_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT=>REGISTRATION_TOTAL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_NOSIGNAL=>true,
        CURLOPT_USERAGENT=>'CC-ROT/'.VERSION
    ]);
    $id=(int)$handle;
    $registrationManager['buffers'][$id]='';
    $registrationManager['active']=array_merge($meta,['type'=>$type,'url'=>$url,'handle'=>$handle,'startedAt'=>microtime(true)]);
    if (curl_multi_add_handle($registrationManager['multi'],$handle)!==CURLM_OK) {
        unset($registrationManager['buffers'][$id]);
        curl_close($handle);
        $registrationManager['active']=null;
        return false;
    }
    return true;
}

function registrationFailureDelay($failures) {
    $failures=max(1,(int)$failures);
    return min(3600,60*(2**min(6,$failures-1)))+registrationJitter();
}

function failRegistrationJob(array $meta,$reason) {
    global $registrationManager;

    $now=time();
    if ($meta['type']==='directory') {
        $failures=isset($registrationManager['state']['directoryFailures'])?(int)$registrationManager['state']['directoryFailures']+1:1;
        $registrationManager['state']['directoryFailures']=$failures;
        $registrationManager['directoryRetryAt']=$now+registrationFailureDelay($failures);
        L("Proxy directory unavailable: $reason; retry later");
        saveRegistrationState();
        return;
    }
    if (!isset($meta['proxyId'],$registrationManager['state']['proxies'][$meta['proxyId']])) {return;}
    $record=&$registrationManager['state']['proxies'][$meta['proxyId']];
    $failures=isset($record['failures'])?(int)$record['failures']+1:1;
    $record['failures']=$failures;
    $record['lastError']=substr((string)$reason,0,96);
    $record['nextDueAt']=$now+registrationFailureDelay($failures);
    L("Proxy {$meta['proxyId']} registration unavailable: $reason");
    saveRegistrationState();
}

function responseMessageDigest(array $messages) {
    $json=json_encode(array_values($messages),JSON_UNESCAPED_SLASHES);
    return hash('sha256',$json===false?'[]':$json);
}

function registrationResponseMac(array $record,array $body) {
    global $rotId,$tikker;

    if (!isset($record['authToken'],$record['proxyId']) || !isset($body['timestamp'],$body['status'],$body['expiresIn'],$body['retryAfter'],$body['messageSequence'],$body['messages'])) {return false;}
    $ok=!empty($body['ok'])?'1':'0';
    $error=isset($body['error'])?(string)$body['error']:'';
    $nickname=isset($body['nickname'])?(string)$body['nickname']:'';
    $latency=isset($body['statusLatencyMs']) && is_int($body['statusLatencyMs'])?(string)$body['statusLatencyMs']:'';
    $serverTime=isset($body['serverTime']) && is_int($body['serverTime'])?(string)$body['serverTime']:'';
    $message='CCP1|REGISTRATION-RES|'.$record['proxyId'].'|'.$rotId.'|'.$tikker.'|'.$body['timestamp'].'|'.$ok.'|'.$body['status'].'|'.$error.'|'.$nickname.'|'.$body['expiresIn'].'|'.$body['retryAfter'].'|'.$latency.'|'.$body['messageSequence'].'|'.responseMessageDigest($body['messages']).'|'.$serverTime;
    return hash_hmac('sha256',$message,$record['authToken']);
}

function appendProxyMessages(array &$record,array $messages,$messageSequence) {
    $ack=isset($record['ackSequence'])?(int)$record['ackSequence']:0;
    $previous=$ack;
    $lastSeen=$ack;
    foreach ($messages as $message) {
        if (!is_array($message) || !isset($message['sequence'],$message['time'],$message['code'],$message['text']) || !is_int($message['sequence']) || $message['sequence']<1 || $message['sequence']<=$lastSeen || !is_int($message['time']) || !is_string($message['code']) || !preg_match('/^[A-Z0-9_]{3,48}$/',$message['code']) || !is_string($message['text']) || strlen($message['text'])>256) {return false;}
        if (isset($message['count']) && (!is_int($message['count']) || $message['count']<1)) {return false;}
        if (isset($message['lastTime']) && !is_int($message['lastTime'])) {return false;}
        $line=['time'=>gmdate('c',$message['time']),'proxyId'=>$record['proxyId'],'sequence'=>$message['sequence'],'code'=>$message['code'],'text'=>$message['text']];
        if (isset($message['count'])) {$line['count']=$message['count'];}
        if (isset($message['lastTime'])) {$line['lastTime']=$message['lastTime'];}
        $encoded=json_encode($line,JSON_UNESCAPED_SLASHES);
        if ($encoded===false || @file_put_contents(ROOT.'proxy-messages.log',$encoded."\n",FILE_APPEND|LOCK_EX)===false) {return false;}
        $lastSeen=$message['sequence'];
    }
    if (!is_int($messageSequence) || $messageSequence<$previous || $messageSequence!==$lastSeen) {return false;}
    $record['ackSequence']=$lastSeen;
    return true;
}

function handleDirectoryResponse(array $meta,$httpCode,$raw) {
    global $registrationManager;

    if ($httpCode!==200) {failRegistrationJob($meta,'HTTP_'.$httpCode);return;}
    $decoded=json_decode($raw,true);
    $directory=validateProxyDirectory($decoded);
    if ($directory===false) {failRegistrationJob($meta,'INVALID_DIRECTORY');return;}
    $registrationManager['state']['directoryFailures']=0;
    $registrationManager['directoryRetryAt']=0;
    mergeRegistrationDirectory($directory,$decoded['generatedAt'],$decoded['expiresAt']);
    L("Proxy directory updated: ".count($directory)." active");
}

function handleRegisterResponse(array $meta,$httpCode,$raw) {
    global $registrationManager,$rotId,$rotNickname,$tikker;

    if (!isset($meta['proxyId'],$registrationManager['state']['proxies'][$meta['proxyId']])) {return;}
    $record=&$registrationManager['state']['proxies'][$meta['proxyId']];
    $decoded=json_decode($raw,true);
    if ($httpCode!==201 || !is_array($decoded) || !isset($decoded['ok'],$decoded['protocol'],$decoded['proxyId'],$decoded['rotId'],$decoded['nickname'],$decoded['coin'],$decoded['status'],$decoded['authToken'],$decoded['expiresIn'],$decoded['timestampTolerance']) || $decoded['ok']!==true || $decoded['protocol']!==1 || !hash_equals($record['proxyId'],$decoded['proxyId']) || !hash_equals($rotId,$decoded['rotId']) || !hash_equals($rotNickname,$decoded['nickname']) || $decoded['coin']!==$tikker || $decoded['status']!=='CANDIDATE' || !is_string($decoded['authToken']) || !preg_match('/^[0-9a-f]{64}$/',$decoded['authToken']) || !is_int($decoded['expiresIn']) || $decoded['expiresIn']<1 || !is_int($decoded['timestampTolerance']) || $decoded['timestampTolerance']<30 || $decoded['timestampTolerance']>600) {
        failRegistrationJob($meta,'INVALID_REGISTER_RESPONSE');
        return;
    }
    $record['authToken']=$decoded['authToken'];
    $record['status']='CANDIDATE';
    $record['expiresAt']=time()+$decoded['expiresIn'];
    $record['timestampTolerance']=$decoded['timestampTolerance'];
    $record['clockOffset']=0;
    $record['ackSequence']=0;
    $record['failures']=0;
    $record['lastError']='';
    $record['nextDueAt']=time()+1;
    if (!saveRegistrationState()) {
        $record['authToken']='';
        $record['nextDueAt']=time()+300;
        return;
    }
    L("Registered candidate at {$record['proxyId']}");
}

function validRegistrationMessages($messages) {
    return is_array($messages) && count($messages)<=16;
}

function handleStatusResponse(array $meta,$httpCode,$raw) {
    global $registrationManager,$rotId,$tikker;

    if (!isset($meta['proxyId'],$registrationManager['state']['proxies'][$meta['proxyId']])) {return;}
    $record=&$registrationManager['state']['proxies'][$meta['proxyId']];
    $decoded=json_decode($raw,true);
    if (($httpCode===401 || $httpCode===404 || $httpCode===409) && is_array($decoded) && isset($decoded['error']) && in_array($decoded['error'],['INVALID_REGISTRATION_AUTH','REGISTRATION_NOT_FOUND','REGISTRATION_CHANGED'],true)) {
        $record['authToken']='';
        $record['ackSequence']=0;
        $record['nextDueAt']=time()+300;
        saveRegistrationState();
        return;
    }
    $signed=$httpCode===200 || $httpCode===409;
    if (!$signed || !is_array($decoded) || !isset($decoded['ok'],$decoded['protocol'],$decoded['proxyId'],$decoded['rotId'],$decoded['coin'],$decoded['status'],$decoded['expiresIn'],$decoded['retryAfter'],$decoded['messageSequence'],$decoded['messages'],$decoded['timestamp'],$decoded['mac']) || $decoded['protocol']!==1 || !hash_equals($record['proxyId'],$decoded['proxyId']) || !hash_equals($rotId,$decoded['rotId']) || $decoded['coin']!==$tikker || !in_array($decoded['status'],['CANDIDATE','READY','LEADING','RECOVERING','QUARANTINED','ENDED'],true) || !validRegistrationMessages($decoded['messages']) || !is_int($decoded['expiresIn']) || $decoded['expiresIn']<0 || !is_int($decoded['retryAfter']) || $decoded['retryAfter']<0 || !is_int($decoded['messageSequence']) || $decoded['messageSequence']<0 || !is_int($decoded['timestamp']) || !is_string($decoded['mac'])) {
        if ($httpCode===401 || $httpCode===404) {$record['authToken']='';$record['ackSequence']=0;$record['nextDueAt']=time()+300;saveRegistrationState();return;}
        failRegistrationJob($meta,'INVALID_STATUS_RESPONSE');
        return;
    }
    $expected=registrationResponseMac($record,$decoded);
    if ($expected===false || !preg_match('/^[0-9a-f]{64}$/',$decoded['mac']) || !hash_equals($expected,$decoded['mac'])) {failRegistrationJob($meta,'INVALID_STATUS_MAC');return;}
    $localNow=time();
    if ($httpCode===409 && isset($decoded['error'],$decoded['serverTime']) && $decoded['error']==='CLOCK_SKEW' && is_int($decoded['serverTime'])) {
        $record['clockOffset']=$decoded['serverTime']-$localNow;
        $record['nextDueAt']=$localNow+60;
        $record['failures']=0;
        saveRegistrationState();
        L("Adjusted proxy clock for {$record['proxyId']}");
        return;
    }
    $expectedNow=$localNow+(isset($record['clockOffset'])?(int)$record['clockOffset']:0);
    if (abs($expectedNow-$decoded['timestamp'])>(isset($record['timestampTolerance'])?(int)$record['timestampTolerance']:REGISTRATION_TIMESTAMP_TOLERANCE)) {failRegistrationJob($meta,'STATUS_CLOCK_INVALID');return;}
    if ($httpCode!==200 || $decoded['ok']!==true) {failRegistrationJob($meta,'STATUS_REJECTED');return;}
    $previousAck=isset($record['ackSequence'])?(int)$record['ackSequence']:0;
    if (!appendProxyMessages($record,$decoded['messages'],$decoded['messageSequence'])) {failRegistrationJob($meta,'MESSAGE_STORAGE_FAILED');return;}
    $record['status']=$decoded['status'];
    $record['expiresAt']=$localNow+max(0,$decoded['expiresIn']);
    $record['failures']=0;
    $record['lastError']='';
    $delay=$decoded['retryAfter']>0?$decoded['retryAfter']:REGISTRATION_INTERVAL+registrationJitter();
    if ($decoded['status']==='ENDED') {$record['authToken']='';$delay=max(300,$delay);}
    $record['nextDueAt']=$localNow+max(30,$delay);
    if (!saveRegistrationState()) {
        $record['ackSequence']=$previousAck;
        $record['nextDueAt']=$localNow+300;
        return;
    }
    L("Proxy {$record['proxyId']} status: {$record['status']}");
}

function finishRegistrationTransfer(array $meta,$curlResult,$httpCode,$raw) {
    if ($curlResult!==CURLE_OK) {failRegistrationJob($meta,'CURL_'.$curlResult);return;}
    if ($meta['type']==='directory') {handleDirectoryResponse($meta,$httpCode,$raw);return;}
    if ($meta['type']==='register') {handleRegisterResponse($meta,$httpCode,$raw);return;}
    if ($meta['type']==='status') {handleStatusResponse($meta,$httpCode,$raw);}
}

function pumpRegistrationManager() {
    global $registrationManager;

    if ($registrationManager['active']===null) {return;}
    do {
        $status=curl_multi_exec($registrationManager['multi'],$running);
    } while ($status===CURLM_CALL_MULTI_PERFORM);
    while (($info=curl_multi_info_read($registrationManager['multi']))!==false) {
        if (!isset($info['handle']) || $registrationManager['active']===null || $info['handle']!==$registrationManager['active']['handle']) {continue;}
        $handle=$info['handle'];
        $id=(int)$handle;
        $raw=isset($registrationManager['buffers'][$id])?$registrationManager['buffers'][$id]:'';
        $httpCode=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
        $meta=$registrationManager['active'];
        curl_multi_remove_handle($registrationManager['multi'],$handle);
        curl_close($handle);
        unset($registrationManager['buffers'][$id]);
        $registrationManager['active']=null;
        finishRegistrationTransfer($meta,isset($info['result'])?(int)$info['result']:CURLE_FAILED_INIT,$httpCode,$raw);
    }
}

function startDirectoryRequest() {
    global $registrationManager;

    $seed=$registrationManager['seeds'][$registrationManager['seedIndex']%count($registrationManager['seeds'])];
    $registrationManager['seedIndex']++;
    return startRegistrationTransfer('directory',$seed,['operation'=>'proxyDirectory','protocol'=>1],[]);
}

function startProxyRegistration(array $record) {
    global $rotId,$rotNickname,$tikker;

    return startRegistrationTransfer('register',$record['proxyUrl'],[
        'operation'=>'rotRegister',
        'protocol'=>1,
        'coin'=>$tikker,
        'rotId'=>$rotId,
        'nickname'=>$rotNickname,
        'port'=>(int)SOCKET
    ],['proxyId'=>$record['proxyId']]);
}

function startProxyStatus(array $record) {
    global $rotId,$tikker;

    $ack=isset($record['ackSequence'])?(int)$record['ackSequence']:0;
    $timestamp=time()+(isset($record['clockOffset'])?(int)$record['clockOffset']:0);
    $message='CCP1|REGISTRATION|'.$record['proxyId'].'|'.$rotId.'|'.$tikker.'|'.$timestamp.'|'.$ack;
    return startRegistrationTransfer('status',$record['proxyUrl'],[
        'operation'=>'rotRegistrationStatus',
        'coin'=>$tikker,
        'rotId'=>$rotId,
        'timestamp'=>$timestamp,
        'ackSequence'=>$ack,
        'mac'=>hash_hmac('sha256',$message,$record['authToken'])
    ],['proxyId'=>$record['proxyId']]);
}

function scheduleRegistrationWork() {
    global $registrationManager;

    if ($registrationManager['active']!==null) {return;}
    $now=time();
    $fetched=(int)$registrationManager['state']['directoryFetchedAt'];
    $expired=(int)$registrationManager['state']['directoryExpiresAt'];
    $directoryMissing=count($registrationManager['state']['directory'])===0;
    $refreshDue=$fetched+REGISTRATION_DIRECTORY_LIFETIME<=$now;
    if (($directoryMissing || $refreshDue) && $registrationManager['directoryRetryAt']<=$now) {
        if (!startDirectoryRequest()) {$registrationManager['directoryRetryAt']=$now+60;}
        return;
    }
    if ($expired+REGISTRATION_DIRECTORY_RETENTION<$now) {return;}
    $selected=selectedProxyIds();
    $due=[];
    foreach ($registrationManager['state']['proxies'] as $proxyId=>$record) {
        if (!isset($selected[$proxyId]) || !is_array($record) || !isset($record['proxyUrl'])) {continue;}
        $next=isset($record['nextDueAt'])?(int)$record['nextDueAt']:0;
        if ($next<=$now) {$due[$proxyId]=$next;}
    }
    if (count($due)===0) {return;}
    asort($due,SORT_NUMERIC);
    $proxyId=(string)key($due);
    $record=$registrationManager['state']['proxies'][$proxyId];
    $hasToken=isset($record['authToken']) && is_string($record['authToken']) && preg_match('/^[0-9a-f]{64}$/',$record['authToken']);
    $started=$hasToken?startProxyStatus($record):startProxyRegistration($record);
    if (!$started) {
        $registrationManager['state']['proxies'][$proxyId]['nextDueAt']=$now+60;
        saveRegistrationState();
    }
}

function registrationTick() {
    pumpRegistrationManager();
    scheduleRegistrationWork();
    pumpRegistrationManager();
}

function registrationTransferActive() {
    global $registrationManager;
    return $registrationManager['active']!==null;
}

function proxyRegistrationRecord($proxyId) {
    global $registrationManager;

    if (!isset($registrationManager['state']['proxies'][$proxyId]) || !is_array($registrationManager['state']['proxies'][$proxyId])) {return false;}
    $record=$registrationManager['state']['proxies'][$proxyId];
    return isset($record['authToken']) && is_string($record['authToken']) && preg_match('/^[0-9a-f]{64}$/',$record['authToken'])?$record:false;
}

function blockHashAtHeight($requestedHeight) {
    if (!is_int($requestedHeight) || $requestedHeight<0) {return false;}
    $handle=@fopen(DATA.'blockhashes','rb');
    if ($handle===false) {return false;}
    if (fseek($handle,$requestedHeight*65,SEEK_SET)!==0) {fclose($handle);return false;}
    $hash=fread($handle,64);
    fclose($handle);
    return is_string($hash) && preg_match('/^[0-9a-f]{64}$/',$hash)?$hash:false;
}

function defaultCheckpointHeights($tip) {
    $mature=max(1,$tip-100);
    $latest=(int)floor($mature/10000)*10000;
    if ($latest<1) {$latest=$mature;}
    $heights=[];
    for ($i=2;$i>=0;$i--) {
        $candidate=$latest-$i*10000;
        if ($candidate>=1) {$heights[$candidate]=$candidate;}
    }
    if (count($heights)===0 && $mature>=1) {$heights[$mature]=$mature;}
    return array_values($heights);
}

function privateStatusResponse($requestId,$parameters) {
    global $height,$lastBlockHash,$tikker,$raceToTheTop;

    $parts=explode(',',$parameters);
    $coin=array_shift($parts);
    if ($coin!==$tikker || !preg_match('/^[0-9a-f]{32}$/',$requestId) || count($parts)>8) {return false;}
    $tip=max(0,$height-1);
    $requested=[];
    foreach ($parts as $part) {
        if (!preg_match('/^[1-9][0-9]*$/',$part)) {return false;}
        $checkpoint=(int)$part;
        if ($checkpoint>$tip) {return false;}
        $requested[$checkpoint]=$checkpoint;
    }
    if (count($requested)===0) {foreach (defaultCheckpointHeights($tip) as $checkpoint) {$requested[$checkpoint]=$checkpoint;}}
    ksort($requested,SORT_NUMERIC);
    $checkpoints=[];
    foreach ($requested as $checkpoint) {
        $hash=blockHashAtHeight($checkpoint);
        if ($hash===false) {return false;}
        $checkpoints[(string)$checkpoint]=$hash;
    }
    if (count($checkpoints)<1 || count($checkpoints)>8 || !is_string($lastBlockHash) || !preg_match('/^[0-9a-f]{64}$/',$lastBlockHash)) {return false;}
    return json_encode([
        'ok'=>true,
        'id'=>$requestId,
        'coin'=>$tikker,
        'ready'=>!$raceToTheTop,
        'recovering'=>$raceToTheTop,
        'height'=>$tip,
        'blockHash'=>$lastBlockHash,
        'checkpoints'=>$checkpoints
    ],JSON_UNESCAPED_SLASHES);
}

function handlePrivateProxyRequest($request) {
    global $rotId,$tikker;

    $outer=explode('|',trim($request),6);
    if (count($outer)!==6 || $outer[0]!=='CCP1') {return false;}
    $proxyId=validProxyId($outer[1]);
    if ($proxyId===false || !hash_equals($rotId,$outer[2]) || !preg_match('/^-?[0-9]+$/',$outer[3]) || !preg_match('/^[0-9a-f]{64}$/',$outer[4])) {return false;}
    $record=proxyRegistrationRecord($proxyId);
    if ($record===false) {return false;}
    $timestamp=(int)$outer[3];
    $expectedNow=time()+(isset($record['clockOffset'])?(int)$record['clockOffset']:0);
    $tolerance=isset($record['timestampTolerance'])?(int)$record['timestampTolerance']:REGISTRATION_TIMESTAMP_TOLERANCE;
    if (abs($expectedNow-$timestamp)>$tolerance) {return false;}
    $expected=hash_hmac('sha256','CCP1|REQ|'.$proxyId.'|'.$rotId.'|'.$timestamp.'|'.$outer[5],$record['authToken']);
    if (!hash_equals($expected,$outer[4])) {return false;}
    $inner=explode('|',$outer[5],3);
    if (count($inner)!==3 || $inner[1]!=='status') {return false;}
    $body=privateStatusResponse($inner[0],$inner[2]);
    if ($body===false) {return false;}
    $responseTimestamp=$expectedNow;
    $mac=hash_hmac('sha256','CCP1|RES|'.$proxyId.'|'.$rotId.'|'.$responseTimestamp.'|'.$body,$record['authToken']);
    return 'CCP1|'.$proxyId.'|'.$rotId.'|'.$responseTimestamp.'|'.$mac.'|'.$body;
}

function handleSocketLine($request) {
    if (strpos($request,'CCP1|')===0) {
        $response=handlePrivateProxyRequest($request);
        return $response===false?'?':$response;
    }
    return handleClientRequest($request);
}

function reverseHex(string $hex): string {
    return implode('', array_reverse(str_split($hex, 2)));
}
function computeBlockHash(string $header80): string {
    return reverseHex(bin2hex(hash('sha256', hash('sha256', $header80, true), true)));
}

/**
 * extractBlocksFromStream serves two purposes.
 * At first it races to the 'end' of the blockchain by parsing all blocks in blk*.dat
 * Blocks are returned one by one, completely parsed, to build the main indexes during a '$raceToTheTop' 
 *
 * Once at the top it starts handling and prioritizing client requests
 * If all client-requests are handled it tails the blk*.dat stream.
 *
 */
function extractBlocksFromStream(): Generator {
    global $RPC,$BLOCKINDEX,$raceToTheTop,$orphan,$blkvalid,$parseContext,$fullBackup,$height,$blockFiles,$tikker;
    
    $coreFailure=false;
    $pollDelayMicro = 500000; // 0.1s; Poll delay when waiting for new data
    $tipReached=false;
    $top=count($BLOCKINDEX->hashMap);

    $fileNumber=-1;
    if ($parseContext['currentFile']=="") {
        $fileNumber=0;
        $currentFile=$blockFiles[$fileNumber];
        $offset=0;
    } else {
        $currentFile=$parseContext['currentFile'];
        $offset=$parseContext['offset'];
        for ($i=0;$i<count($blockFiles);$i++){
            if ($blockFiles[$i]==$currentFile) {$fileNumber=$i;break;}
        }
    }
    if ($fileNumber==-1){L("Strange 'currentFile' {$currentFile} in parsecontext\n");die();}
    
    $handle = fopen($currentFile, 'rb');
    $last_error="";$repeat_error=0;
    while (true) {                            // 'yields' after every block encountered
        while (true) {                        // In the blk*.dat file-stream fork-blocks occur and can be skipped
            if ($raceToTheTop) { // Read available complete blocks from current file;
                if (feof($handle)) {break;}       // Stop if at end-of-file
                $parseContext['currentFile'] = $currentFile;
                $parseContext['offset']      = ftell($handle);
                $header                      = fread($handle, 8); // Read magic (4B) + length (4B)
                if (strlen($header) < 8) {break;} // Incomplete header -> no more complete blocks right now
                $magic       = substr($header, 0, 4);
                $lengthData  = substr($header, 4, 4);
                $blockLength = unpack('Vlength', $lengthData)['length'];
                if ($magic !== MAGIC) { // check magic bytes (if zero's instead, switch to RPC retrieval)
                    if (bin2hex($magic) === str_repeat('00', 4)) {
                        $raceToTheTop=false;
                        L("End of disk blockstream reached at height ".($height-1)."; Turn to RPC; Waiting for next block and client requests... \n");
                        continue; // turn to RPC
                    }
                    throw new \Exception("Invalid magic bytes at offset $offset in file $currentFile: " . bin2hex($magic));
                }            

                $blockData = fread($handle, $blockLength); // Attempt to read the full block
                if (strlen($blockData) < $blockLength) {
                    throw new Exception("Incomplete block at offset $offset in file $file");
                }
    
                $header        = substr($blockData, 0, 80);
                $blockHash     = computeBlockHash($header);
                $prevBlockHash = bin2hex(strrev(substr($header, 4, 32)));
                $newHeight     = $BLOCKINDEX->hashMap[$blockHash] ?? null;
                if ($newHeight==0) {continue;} // skip genesis block                
                if (is_null($newHeight)) {
                    $offset += 8 + $blockLength;
                    $orphan++;
                    continue;
                }
                $blkvalid++;
            } else { // Turn to RPC
                while (true) {
                    $blockHash     = $RPC->call('getblockhash',[$height]);
                    if ($blockHash<0) {
                        break;
                    } elseif ($blockHash!==null) {break;}   // new block                     
                }
                if ($blockHash<0) {break;} // no block yet
                while (true){
                    $rawHex        = $RPC->call('getblock',[$blockHash, false]);
                    if ($rawHex!==null) {break;}
                }
                $newHeight     = $height;
                if ($tikker=="DEM") {
                    $blockData     = $rawHex;
                    $prevBlockHash = $rawHex['previousblockhash'];
                } else {
                    $blockData     = hex2bin($rawHex);
                    $blockLength   = strlen($blockData);
                    $prevBlockHash = bin2hex(strrev(substr($blockData, 4, 32)));
                }
                if (!$tipReached) {
                    if ($height>=$top) {
                        while(true) {
                            $top=$RPC->call('getblockcount',[]);                                
                            if ($top!==null) {break;}
                        }
                        if ($height-1==$top) {$tipReached=true;} // height is the next block we are waiting for
                    }
                }
            }
            yield [
                'raw'         => $blockData,
                'offset'      => $parseContext['offset'],
                'fileNumber'  => $fileNumber,
                'length'      => $blockLength,
                'hash'        => $blockHash,
                'prevHash'    => $prevBlockHash,
                'id'          => $newHeight
            ];
        }

        // See if there is a next file, otherwise turn to RPC
        if (($fileNumber+1)<count($blockFiles)) {
            $nextFile = $blockFiles[$fileNumber+1];
            if (file_exists($nextFile)) {
                // Close and switch to the new file
                fclose($handle);
                $fileNumber++;
                $currentFile = $nextFile;
                $handle      = fopen($currentFile, 'rb');
                if (!$handle) {
                    throw new \RuntimeException("Unable to open next file $currentFile");
                }
                $offset      = 0;
                continue;
            } else {
                if ($raceToTheTop) {$raceToTheTop=false;}
            }
        } else {
            $raceToTheTop=false;
        }
        
        // No news from blockchain;
        $serviceTime=microtime(true);
        handleSocketRequests($serviceTime+1); // may take longer, but try to return
        
        $requests=glob(Q."/*");
        foreach($requests as $request){
            $destination=str_replace(Q,A,$request);
            $cmd=file($request,FILE_IGNORE_NEW_LINES);
            if (file_exists($destination)) {@unlink($request);} else {rename($request,$destination);}
            $response = handleClientRequest($cmd[0]);
            echo $response."\n";
            break;
        }
        
    }
}
function stripResources(array $table){ // to allow serialization
    // 
    $filtered = [];
    foreach ($table as $ksub => $sub) {
        if (is_array($sub)) {   
            foreach ($sub as $k => $v) {
                if (!(($k==P)||($k==KEY))) {
                    $filtered[$ksub][$k] = $v;
                }
            }
        } else {
            $filtered[$ksub]=$sub;
        }
    }
    return $filtered;
}
function readSerializedArray($path) {
    if (!is_file($path)) {return false;}
    $raw=@file_get_contents($path);
    if ($raw===false) {return false;}
    $value=@unserialize($raw,['allowed_classes'=>false]);
    return is_array($value)?$value:false;
}
function writeSerializedArray($path,array $value) {
    $serialized=serialize($value);
    $written=@file_put_contents($path,$serialized,LOCK_EX);
    return $written===strlen($serialized);
}
function backupTableTops() {
    global $TX_table,$PUB_table,$TXO_table;
    return [
        'tx'=>(int)$TX_table['bucket'][TOP],
        'pub'=>(int)$PUB_table['bucket'][TOP],
        'txo'=>(int)$TXO_table['bucket'][TOP]
    ];
}
function validBackupContext($context) {
    if (!is_array($context) || !isset($context['height']) || !is_int($context['height']) || $context['height']<0) {return false;}
    if (!isset($context['hash']) || !is_string($context['hash']) || !preg_match('/^[0-9a-f]{64}$/',$context['hash'])) {return false;}
    if (!isset($context['currentFile']) || !is_string($context['currentFile']) || !isset($context['offset']) || !is_int($context['offset']) || $context['offset']<0) {return false;}
    if (!isset($context['tableTops']) || !is_array($context['tableTops'])) {return false;}
    foreach (['tx','pub','txo'] as $name) {
        if (!isset($context['tableTops'][$name]) || !is_int($context['tableTops'][$name]) || $context['tableTops'][$name]<0) {return false;}
    }
    return true;
}
function backupPartValid($table,$part,$path) {
    if (!is_array($table) || !isset($table[$part]) || !is_array($table[$part]) || !isset($table[$part][SIZE]) || !is_numeric($table[$part][SIZE])) {return false;}
    $size=(int)$table[$part][SIZE];
    if ($size<=0 || (float)$size!==(float)$table[$part][SIZE] || !is_file($path)) {return false;}
    clearstatcache(true,$path);
    return filesize($path)===$size;
}
function loadBackupCandidate($postfix,$full=false) {
    $contextPath=$full?DATA."AUX":DATA.$postfix;
    $context=readSerializedArray($contextPath);
    $tx=readSerializedArray(DATA."TX_aux".($full?'':$postfix));
    $pub=readSerializedArray(DATA."PUB_aux".($full?'':$postfix));
    $txo=readSerializedArray(DATA."TXO_aux".($full?'':$postfix));
    if (!validBackupContext($context) || $tx===false || $pub===false || $txo===false) {return false;}
    if (!isset($tx['bucket'][TOP],$pub['bucket'][TOP],$txo['bucket'][TOP])) {return false;}
    if ((int)$tx['bucket'][TOP]!==$context['tableTops']['tx'] || (int)$pub['bucket'][TOP]!==$context['tableTops']['pub'] || (int)$txo['bucket'][TOP]!==$context['tableTops']['txo']) {return false;}
    $suffix=$full?'':$postfix;
    if (!backupPartValid($tx,'hash',DATA."TX_hash$suffix") || !backupPartValid($pub,'hash',DATA."PUB_hash$suffix")) {return false;}
    if ($full && (!backupPartValid($tx,'bucket',DATA."TX_bucket") || !backupPartValid($pub,'bucket',DATA."PUB_bucket") || !backupPartValid($txo,'bucket',DATA."TXO_bucket"))) {return false;}
    return [
        'postfix'=>$postfix,
        'context'=>$context,
        'tx'=>$tx,
        'pub'=>$pub,
        'txo'=>$txo,
        'modified'=>(int)@filemtime($contextPath)
    ];
}
function backupIsCanonical(array $candidate) {
    global $RPC;
    while (true) {
        $hash=$RPC->call('getblockhash',[$candidate['context']['height']]);
        if ($hash!==null) {break;}
    }
    return is_string($hash) && hash_equals($candidate['context']['hash'],$hash);
}
function backupPostfixForWrite() {
    $first=loadBackupCandidate('_backup_1',false);
    $second=loadBackupCandidate('_backup_2',false);
    if ($first===false) {return '_backup_1';}
    if ($second===false) {return '_backup_2';}
    if ($first['context']['height']<$second['context']['height']) {return '_backup_1';}
    if ($second['context']['height']<$first['context']['height']) {return '_backup_2';}
    return @filemtime(DATA."_backup_1")<=@filemtime(DATA."_backup_2")?'_backup_1':'_backup_2';
}
function selectRewindBackup() {
    $candidates=[];
    foreach (['_backup_1','_backup_2'] as $postfix) {
        $candidate=loadBackupCandidate($postfix,false);
        if ($candidate===false) {
            L("Reject incomplete rewind backup $postfix\n");
        } elseif (backupIsCanonical($candidate)) {
            $candidates[]=$candidate;
        } else {
            L("Reject non-canonical rewind backup $postfix at height {$candidate['context']['height']}\n");
        }
    }
    usort($candidates,function($left,$right){
        if ($left['context']['height']===$right['context']['height']) {return $right['modified']-$left['modified'];}
        return $right['context']['height']-$left['context']['height'];
    });
    return count($candidates)>0?$candidates[0]:false;
}
function backup($full=false,$checkpointHeight=null,$checkpointHash=null){
    /* The tables contain every indexed block through checkpointHeight.
       Recovery resumes at checkpointHeight + 1.
    */
    global $TX_table,$PUB_table,$TXO_table,$parseContext;

    if (!is_int($checkpointHeight) || $checkpointHeight<0 || !is_string($checkpointHash) || !preg_match('/^[0-9a-f]{64}$/',$checkpointHash)) {
        die("Invalid backup checkpoint\n");
    }
    $parseContext['height']=$checkpointHeight;
    $parseContext['hash']=$checkpointHash;
    $parseContext['tableTops']=backupTableTops();
    $time=microtime(true);
    if (!$full) {
        $postfix=backupPostfixForWrite();
        L("Backup $postfix at height $checkpointHeight: ");
        if (!writeSerializedArray(DATA."TX_aux$postfix",stripResources($TX_table)) || !writeSerializedArray(DATA."PUB_aux$postfix",stripResources($PUB_table)) || !writeSerializedArray(DATA."TXO_aux$postfix",stripResources($TXO_table))) {
            die("Cannot write rewind backup metadata\n");
        }
        dump_index($TX_table,"hash",DATA."TX_hash$postfix");
        dump_index($PUB_table,"hash",DATA."PUB_hash$postfix");
        if (!backupPartValid(stripResources($TX_table),'hash',DATA."TX_hash$postfix") || !backupPartValid(stripResources($PUB_table),'hash',DATA."PUB_hash$postfix") || !writeSerializedArray(DATA.$postfix,$parseContext)) {
            die("Incomplete rewind backup\n");
        }
    } else {
        L("Backup Full at height $checkpointHeight: ");
        if (!writeSerializedArray(DATA."TX_aux",stripResources($TX_table)) || !writeSerializedArray(DATA."PUB_aux",stripResources($PUB_table)) || !writeSerializedArray(DATA."TXO_aux",stripResources($TXO_table))) {
            die("Cannot write full backup metadata\n");
        }
        dump_index($TX_table,"hash",DATA."TX_hash");
        dump_index($PUB_table,"hash",DATA."PUB_hash");
        dump_index($TX_table,"bucket",DATA."TX_bucket");
        dump_index($PUB_table,"bucket",DATA."PUB_bucket");
        dump_index($TXO_table,"bucket",DATA."TXO_bucket");
        $valid=backupPartValid(stripResources($TX_table),'hash',DATA."TX_hash") && backupPartValid(stripResources($PUB_table),'hash',DATA."PUB_hash") && backupPartValid(stripResources($TX_table),'bucket',DATA."TX_bucket") && backupPartValid(stripResources($PUB_table),'bucket',DATA."PUB_bucket") && backupPartValid(stripResources($TXO_table),'bucket',DATA."TXO_bucket");
        if (!$valid || !writeSerializedArray(DATA."AUX",$parseContext)) {die("Incomplete full backup\n");}
    }
    L((microtime(true)-$time)."(s)\n");
}
function restoreAffectedPub($pubIndex,$backupTxTop,$backupPubTop,$backupTxoTop) {
    global $TX_table,$PUB_table,$TXO_table;

    if ($pubIndex<1 || $pubIndex>$backupPubTop) {die("Invalid affected PUB index\n");}
    $pub=hashtable_read($PUB_table['bucket'],$pubIndex);
    $txoIndex=$pub[2];
    $visited=[];
    $lastTxo=0;
    $lastChangeHeight=0;
    while (true) {
        if ($txoIndex<1 || $txoIndex>$backupTxoTop || isset($visited[$txoIndex])) {die("Broken TXO linked list during rewind\n");}
        $visited[$txoIndex]=true;
        $txo=hashtable_read($TXO_table['bucket'],$txoIndex);
        if ($txo[3]!==$pubIndex || $txo[0]<1 || $txo[0]>$backupTxTop) {die("Invalid TXO ownership during rewind\n");}
        $tx=hashtable_read($TX_table['bucket'],$txo[0]);
        $lastChangeHeight=max($lastChangeHeight,(int)$tx[1]);
        if ($txo[4]!==0) {
            if ($txo[4]<1 || $txo[4]>$backupTxTop) {die("Invalid spend pointer during rewind\n");}
            $spend=hashtable_read($TX_table['bucket'],$txo[4]);
            $lastChangeHeight=max($lastChangeHeight,(int)$spend[1]);
        }
        $lastTxo=$txoIndex;
        $next=(int)$txo[5];
        if ($next>$TXO_table['bucket'][TOP]) {die("TXO pointer beyond live index during rewind\n");}
        if ($next===0 || $next>$backupTxoTop) {
            if ($next>$backupTxoTop) {
                $txo[5]=0;
                hashtable_write($TXO_table['bucket'],$txoIndex,$txo);
            }
            break;
        }
        $txoIndex=$next;
    }
    $pub[1]=$lastChangeHeight;
    $pub[3]=$lastTxo;
    hashtable_write($PUB_table['bucket'],$pubIndex,$pub);
}
function repairCollisionPointers(&$table,$pointerIndex) {
    $top=$table['bucket'][TOP];
    for ($i=1;$i<=$top;$i++) {
        $content=hashtable_read($table['bucket'],$i);
        if ($content[$pointerIndex]>$top) {
            $content[$pointerIndex]=0;
            hashtable_write($table['bucket'],$i,$content);
        }
    }
}
function restoreTableKeys() {
    global $TX_table,$PUB_table,$TXO_table;
    $TX_table['hash'][KEY]=ftok(__FILE__,'A');
    $TX_table['bucket'][KEY]=ftok(__FILE__,'B');
    $PUB_table['hash'][KEY]=ftok(__FILE__,'C');
    $PUB_table['bucket'][KEY]=ftok(__FILE__,'D');
    $TXO_table['bucket'][KEY]=ftok(__FILE__,'E');
}
function recover($full=false) { // Rewinds to the highest complete canonical backup-tip
    global $TX_table,$PUB_table,$TXO_table,$parseContext;
    $time=microtime(true);

    if ($full) {
        $candidate=loadBackupCandidate('',true);
        if ($candidate===false || !backupIsCanonical($candidate)) {return false;}
        $parseContext=$candidate['context'];
        $TX_table=$candidate['tx'];
        $PUB_table=$candidate['pub'];
        $TXO_table=$candidate['txo'];
        restoreTableKeys();
        L("Recover Full till height {$parseContext['height']}\n");
        hashtable_initialize($TX_table['hash']);
        hashtable_initialize($TX_table['bucket']);
        hashtable_initialize($PUB_table['hash']);
        hashtable_initialize($PUB_table['bucket']);
        hashtable_initialize($TXO_table['bucket']);
        load_index($TX_table,'hash',DATA."TX_hash");
        load_index($PUB_table,'hash',DATA."PUB_hash");
        load_index($TX_table,'bucket',DATA."TX_bucket");
        load_index($PUB_table,'bucket',DATA."PUB_bucket");
        load_index($TXO_table,'bucket',DATA."TXO_bucket");
        return true;
    }

    $candidate=selectRewindBackup();
    if ($candidate===false) {
        L("No canonical rewind backup; trying full backup\n");
        if (!recover(true)) {die("No complete canonical backup available; index rebuild required\n");}
        return true;
    }
    $parseContext=$candidate['context'];
    $postfix=$candidate['postfix'];
    $backupTxTop=$candidate['tx']['bucket'][TOP];
    $backupPubTop=$candidate['pub']['bucket'][TOP];
    $backupTxoTop=$candidate['txo']['bucket'][TOP];
    $currentTxTop=$TX_table['bucket'][TOP];
    $currentPubTop=$PUB_table['bucket'][TOP];
    $currentTxoTop=$TXO_table['bucket'][TOP];
    if ($backupTxTop>$currentTxTop || $backupPubTop>$currentPubTop || $backupTxoTop>$currentTxoTop) {die("Rewind backup is ahead of live indexes\n");}
    L("Recover $postfix till height {$parseContext['height']}\n");

    hashtable_initialize($TX_table['hash']);
    hashtable_initialize($PUB_table['hash']);
    load_index($TX_table,'hash',DATA."TX_hash$postfix");
    load_index($PUB_table,'hash',DATA."PUB_hash$postfix");

    $affected=[];
    for ($i=$backupTxoTop+1;$i<=$currentTxoTop;$i++) {
        $txo=hashtable_read($TXO_table['bucket'],$i);
        if ($txo[3]<1 || $txo[3]>$currentPubTop) {die("Invalid PUB pointer in removed TXO\n");}
        if ($txo[3]>=1 && $txo[3]<=$backupPubTop) {$affected[$txo[3]]=true;}
    }
    for ($i=1;$i<=$backupTxoTop;$i++) {
        $txo=hashtable_read($TXO_table['bucket'],$i);
        if ($txo[4]>$currentTxTop) {die("Spend pointer beyond live TX index\n");}
        if ($txo[4]>$backupTxTop) {
            $txo[4]=0;
            hashtable_write($TXO_table['bucket'],$i,$txo);
            if ($txo[3]>=1 && $txo[3]<=$backupPubTop) {$affected[$txo[3]]=true;}
        }
    }
    foreach ($affected as $pubIndex=>$dummy) {restoreAffectedPub($pubIndex,$backupTxTop,$backupPubTop,$backupTxoTop);}

    $TX_table['bucket'][TOP]=$backupTxTop;
    $PUB_table['bucket'][TOP]=$backupPubTop;
    $TXO_table['bucket'][TOP]=$backupTxoTop;
    repairCollisionPointers($TX_table,3);
    repairCollisionPointers($PUB_table,4);
    L(($currentTxoTop-$backupTxoTop)." txo's removed; ".count($affected)." pubkeys restored; ".(microtime(true)-$time)."(s)\n");
    backup(true,$parseContext['height'],$parseContext['hash']);
    return true;
}
function closeSocketClient(array &$clients,$id,&$bufferedBytes) {
    if (!isset($clients[$id])) {return;}
    $client=$clients[$id];
    $remainingOutput=max(0,strlen($client['output'])-$client['outputOffset']);
    $bufferedBytes=max(0,$bufferedBytes-strlen($client['input'])-$remainingOutput);
    if (is_resource($client['socket'])) {fclose($client['socket']);}
    unset($clients[$id]);
}

function handleSocketRequests(float $deadline){
    static $clients=[];
    static $server;
    static $bufferedBytes=0;

    if ($server===null) {
        $context=stream_context_create(['socket'=>['backlog'=>SOCKET_BACKLOG]]);
        $server=stream_socket_server(
            "tcp://0.0.0.0:".SOCKET,
            $errno,
            $errstr,
            STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
            $context
        );
        if (!$server) {die("Socket error: $errstr ($errno)");}
        stream_set_blocking($server,false);
    }

    registrationTick();

    while (microtime(true)<$deadline) {
        registrationTick();
        $now=microtime(true);
        $read=[$server];
        $write=[];
        $except=null;
        $waitUntil=$deadline;
        foreach ($clients as $id=>$client) {
            if ($client['output']==='') {
                $read[$id]=$client['socket'];
                $waitUntil=min($waitUntil,$client['readDeadline']);
            } else {
                $write[$id]=$client['socket'];
                $waitUntil=min($waitUntil,$client['writeDeadline']);
            }
        }
        if (registrationTransferActive()) {$waitUntil=min($waitUntil,$now+REGISTRATION_PUMP_INTERVAL);}
        $remaining=max(0.0,$waitUntil-$now);
        $timeoutSec=(int)floor($remaining);
        $timeoutUsec=(int)floor(($remaining-$timeoutSec)*1000000);
        $ready=@stream_select($read,$write,$except,$timeoutSec,$timeoutUsec);
        if ($ready===false) {
            $err=error_get_last();
            $message=is_array($err) && isset($err['message'])?$err['message']:'unknown stream_select error';
            if (strpos($message,'Interrupted system call')!==false) {continue;}
            L("stream_select failed: ".$message);
            break;
        }
        registrationTick();

        foreach ($read as $sock) {
            if ($sock===$server) {
                $acceptedThisPass=0;
                while ($acceptedThisPass<MAX_SOCKET_ACCEPTS_PER_PASS && ($client=@stream_socket_accept($server,0))!==false) {
                    $acceptedThisPass++;
                    if (count($clients)>=MAX_SOCKET_CLIENTS || $bufferedBytes>=MAX_SOCKET_BUFFER_BYTES) {
                        fclose($client);
                        continue;
                    }
                    stream_set_blocking($client,false);
                    $id=(int)$client;
                    $accepted=microtime(true);
                    $clients[$id]=[
                        'socket'=>$client,
                        'input'=>'',
                        'output'=>'',
                        'outputOffset'=>0,
                        'readDeadline'=>$accepted+SOCKET_READ_TIMEOUT,
                        'writeDeadline'=>0.0
                    ];
                }
                continue;
            }
            $id=(int)$sock;
            if (!isset($clients[$id]) || $clients[$id]['output']!=='') {continue;}
            $chunk=@fread($sock,8192);
            if ($chunk===false || ($chunk==='' && feof($sock))) {
                closeSocketClient($clients,$id,$bufferedBytes);
                continue;
            }
            if ($chunk==='') {continue;}
            $clients[$id]['input'].=$chunk;
            $bufferedBytes+=strlen($chunk);
            if (strlen($clients[$id]['input'])>MAX_SOCKET_REQUEST_BYTES || $bufferedBytes>MAX_SOCKET_BUFFER_BYTES) {
                closeSocketClient($clients,$id,$bufferedBytes);
                continue;
            }
            $newline=strpos($clients[$id]['input'],"\n");
            if ($newline===false) {continue;}
            $line=substr($clients[$id]['input'],0,$newline);
            $trailing=substr($clients[$id]['input'],$newline+1);
            if (trim($trailing)!=='') {
                closeSocketClient($clients,$id,$bufferedBytes);
                continue;
            }
            if (DEBUG) {echo $line."\n";}
            $response=handleSocketLine($line)."\n";
            $bufferedBytes-=strlen($clients[$id]['input']);
            $clients[$id]['input']='';
            if (strlen($response)>MAX_SOCKET_RESPONSE_BYTES || $bufferedBytes+strlen($response)>MAX_SOCKET_BUFFER_BYTES) {
                closeSocketClient($clients,$id,$bufferedBytes);
                continue;
            }
            $clients[$id]['output']=$response;
            $clients[$id]['outputOffset']=0;
            $clients[$id]['writeDeadline']=microtime(true)+SOCKET_WRITE_TIMEOUT;
            $bufferedBytes+=strlen($response);
            $write[$id]=$sock;
            if (DEBUG) {echo $response;}
        }

        foreach ($write as $sock) {
            $id=(int)$sock;
            if (!isset($clients[$id]) || $clients[$id]['output']==='') {continue;}
            $remainingOutput=strlen($clients[$id]['output'])-$clients[$id]['outputOffset'];
            $chunk=substr($clients[$id]['output'],$clients[$id]['outputOffset'],min(65536,$remainingOutput));
            $written=@fwrite($sock,$chunk);
            if ($written===false) {
                closeSocketClient($clients,$id,$bufferedBytes);
                continue;
            }
            if ($written>0) {
                $clients[$id]['outputOffset']+=$written;
                $bufferedBytes=max(0,$bufferedBytes-$written);
            }
            if ($clients[$id]['outputOffset']>=strlen($clients[$id]['output'])) {
                closeSocketClient($clients,$id,$bufferedBytes);
            }
        }

        $now=microtime(true);
        foreach (array_keys($clients) as $id) {
            if (!isset($clients[$id])) {continue;}
            $expired=$clients[$id]['output']===''
                ?$now>=$clients[$id]['readDeadline']
                :$now>=$clients[$id]['writeDeadline'];
            if ($expired) {closeSocketClient($clients,$id,$bufferedBytes);}
        }
    }
}
function parse_peers_dat() {
    global $datadir;
    $fp = fopen($datadir."peers.dat", 'rb');
    if (!$fp) {return [];}

    fseek($fp, 4); // Skip magic
    $version = unpack('C', fread($fp, 1))[1]; // Read 1-byte version
    $keysize = unpack('C', fread($fp, 1))[1];
    fread($fp, 32); // Skip NKey
    $nnew = unpack('V', fread($fp, 4))[1];
    $ntried = unpack('V', fread($fp, 4))[1];
    fread($fp, 4); // Skip newBuckets

    $total = $nnew + $ntried;
    $peers = [];

    $oneMonth=time()-30*24*3600;
    for ($i = 0; $i < $total; $i++) {
        $ser_ver = fread($fp, 4);
        $time = unpack('V', fread($fp, 4))[1];
        $services = unpack('P', fread($fp, 8))[1];
        $ip = fread($fp, 16);
        $port = unpack('n', fread($fp, 2))[1];
        $source = fread($fp, 16);
        $last_success = unpack('P', fread($fp, 8))[1];
        $attempts = unpack('V', fread($fp, 4))[1];

        $ip_str = inet_ntop($ip);
        $source_str = inet_ntop($source);

        if (strpos($ip_str, '::ffff:') === 0) {
            $ip_str = preg_replace('/^::ffff:/', '', $ip_str);
        }
        if (strpos($source_str, '::ffff:') === 0) {
            $source_str = preg_replace('/^::ffff:/', '', $source_str);
        }

        if ($time>$oneMonth){
            $peers[] = [
                'ip' => $ip_str,
                'port' => $port,
                'last_seen' => $time,
                'year_month' => $ym,
                'last_success' => $last_success,
                'attempts' => $attempts,
                'services_hex' => sprintf('%016x', $services),
                'services_flags' => decode_services($services),
                'source' => $source_str
            ];
        }
    }

    fclose($fp);
    return $peers;
}

function encodePubsResponse(array $response) {
    $json=json_encode($response,JSON_UNESCAPED_SLASHES);
    if ($json===false) {
        return '{"ok":false,"error":"JSON_ENCODE_FAILED"}';
    }
    return $json;
}
function pubsError($id,$error) {
    return encodePubsResponse([
        'ok'=>false,
        'id'=>$id,
        'error'=>$error
    ]);
}
function transactionIdFromRaw($rawHex) {
    $binary=hex2bin($rawHex);
    if ($binary===false) {
        return false;
    }
    return bin2hex(strrev(hash('sha256',hash('sha256',$binary,true),true)));
}
function sendResponse($id,$ok,$status,$txid,$fields=[]) {
    global $tikker;

    $response=[
        'ok'=>$ok,
        'id'=>$id,
        'coin'=>$tikker,
        'technical'=>false,
        'status'=>$status,
        'txid'=>$txid
    ];
    foreach ($fields as $key=>$value) {
        $response[$key]=$value;
    }
    return encodePubsResponse($response);
}
function handleSendRequest($id,$rawHex) {
    global $RPC;

    $started=microtime(true);
    if (!is_string($id) || $id==='' || strlen($id)>64) {
        return sendResponse($id,false,'REJECTED',null,['error'=>'INVALID_ID','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    if (!is_string($rawHex) || strlen($rawHex)<20 || strlen($rawHex)>MAX_RAW_TRANSACTION_HEX || (strlen($rawHex)%2)!==0 || !ctype_xdigit($rawHex)) {
        return sendResponse($id,false,'REJECTED',null,['error'=>'INVALID_RAW_TRANSACTION','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }

    $rawHex=strtolower($rawHex);
    $localTxid=transactionIdFromRaw($rawHex);
    if ($localTxid===false) {
        return sendResponse($id,false,'REJECTED',null,['error'=>'INVALID_RAW_TRANSACTION','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }

    $core=$RPC->callResult('sendrawtransaction',[$rawHex]);
    $timing=['coreMs'=>$core['durationMs'],'rotMs'=>(int)round((microtime(true)-$started)*1000)];
    if ($core['technical']) {
        return sendResponse($id,false,'UNAVAILABLE',$localTxid,array_merge($timing,['technical'=>true,'error'=>$core['error']]));
    }
    if (!$core['ok']) {
        $message=(string)$core['rpcMessage'];
        $alreadyKnown=($core['rpcCode']===-27)||preg_match('/already/i',$message);
        if ($alreadyKnown) {
            return sendResponse($id,true,'KNOWN',$localTxid,array_merge($timing,['accepted'=>true]));
        }
        return sendResponse($id,false,'REJECTED',$localTxid,array_merge($timing,[
            'accepted'=>false,
            'rpcCode'=>$core['rpcCode'],
            'rpcMessage'=>$message
        ]));
    }

    $coreTxid=is_string($core['result'])?strtolower($core['result']):'';
    if (!preg_match('/^[0-9a-f]{64}$/',$coreTxid) || !hash_equals($localTxid,$coreTxid)) {
        return sendResponse($id,false,'UNAVAILABLE',$localTxid,array_merge($timing,['technical'=>true,'error'=>'CORE_TXID_MISMATCH']));
    }
    return sendResponse($id,true,'ACCEPTED',$localTxid,array_merge($timing,['accepted'=>true]));
}
function handleTransactionStatusRequest($id,$txid) {
    global $RPC,$height,$lastBlockHash,$TX_table;

    $started=microtime(true);
    if (!is_string($id) || $id==='' || strlen($id)>64 || !is_string($txid) || !preg_match('/^[0-9a-fA-F]{64}$/',$txid)) {
        return sendResponse($id,false,'REJECTED',null,['error'=>'INVALID_TRANSACTION_ID','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    $txid=strtolower($txid);
    list($index,$record)=find($TX_table,hex2bin($txid));
    if ($index!==false && isset($record[0]) && bin2hex($record[0])===$txid) {
        $blockHeight=(int)$record[1];
        return sendResponse($id,true,'CONFIRMED',$txid,[
            'confirmed'=>true,
            'blockHeight'=>$blockHeight,
            'confirmations'=>max(1,$height-$blockHeight),
            'height'=>max(0,$height-1),
            'blockHash'=>$lastBlockHash,
            'coreMs'=>0,
            'rotMs'=>(int)round((microtime(true)-$started)*1000)
        ]);
    }

    $core=$RPC->callResult('getrawmempool',[]);
    $timing=['coreMs'=>$core['durationMs'],'rotMs'=>(int)round((microtime(true)-$started)*1000)];
    if ($core['technical'] || !$core['ok'] || !is_array($core['result'])) {
        return sendResponse($id,false,'UNAVAILABLE',$txid,array_merge($timing,['technical'=>true,'error'=>'CORE_STATUS_UNAVAILABLE']));
    }
    if (in_array($txid,$core['result'],true)) {
        return sendResponse($id,true,'MEMPOOL',$txid,array_merge($timing,['confirmed'=>false]));
    }
    return sendResponse($id,true,'UNKNOWN',$txid,array_merge($timing,['confirmed'=>false]));
}
function coinValueToAtomicUnits($value) {
    global $unitsPerCoin,$coinDecimals;

    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        return false;
    }
    $pattern='/^(0|[1-9][0-9]*)(\.[0-9]{1,'.$coinDecimals.'})?$/';
    if (is_string($value) && !preg_match($pattern,$value)) {
        return false;
    }
    $decimal=is_string($value)?$value:number_format($value,$coinDecimals,'.','');
    $parts=explode('.',$decimal,2);
    $whole=$parts[0];
    $fraction=isset($parts[1])?str_pad($parts[1],$coinDecimals,'0'):str_repeat('0',$coinDecimals);
    if (strlen($fraction)>$coinDecimals || strlen($whole)>10) {
        return false;
    }
    $units=((int)$whole)*$unitsPerCoin+(int)$fraction;
    return $units>=0?$units:false;
}
function coreP2pkhOutputAddress(array $output) {
    global $versionByte;

    if (!isset($output['scriptPubKey']) || !is_array($output['scriptPubKey'])) {
        return false;
    }
    $script=$output['scriptPubKey'];
    if (array_key_exists('hex',$script)) {
        if (!is_string($script['hex']) || !preg_match('/^76a914([0-9a-fA-F]{40})88ac$/',$script['hex'],$matches)) {
            return false;
        }
        return address_from_pubkeyhash(hex2bin($matches[1]));
    }
    if (!isset($script['type']) || $script['type']!=='pubkeyhash' || !isset($script['addresses']) || !is_array($script['addresses']) || count($script['addresses'])!==1 || !is_string($script['addresses'][0])) {
        return false;
    }
    $address=$script['addresses'][0];
    $payload=base58check_decode($address);
    if ($payload===false || strlen($payload)!==21 || ord($payload[0])!==$versionByte) {
        return false;
    }
    return $address;
}
function handleZeroConfirmationRequest($id,$parameters) {
    global $RPC,$height,$lastBlockHash,$TX_table,$TXO_table,$PUB_table,$versionByte;

    $started=microtime(true);
    $parts=explode(',',$parameters);
    if (!is_string($id) || $id==='' || strlen($id)>64 || count($parts)!==3) {
        return sendResponse($id,false,'REJECTED',null,['address'=>'','amountSats'=>0,'error'=>'INVALID_PAYMENT_RECEIPT','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    list($txid,$address,$amountText)=$parts;
    $payload=base58check_decode($address);
    if (!preg_match('/^[0-9a-fA-F]{64}$/',$txid) || $payload===false || strlen($payload)!==21 || ord($payload[0])!==$versionByte || !preg_match('/^[1-9][0-9]*$/',$amountText)) {
        return sendResponse($id,false,'REJECTED',preg_match('/^[0-9a-fA-F]{64}$/',$txid)?strtolower($txid):null,['address'=>$address,'amountSats'=>(int)$amountText,'error'=>'INVALID_PAYMENT_RECEIPT','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    $txid=strtolower($txid);
    $amountSats=(int)$amountText;
    if ($amountSats<=0) {
        return sendResponse($id,false,'REJECTED',$txid,['address'=>$address,'amountSats'=>$amountSats,'error'=>'INVALID_PAYMENT_RECEIPT','rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }

    list($index,$record)=find($TX_table,hex2bin($txid));
    if ($index!==false && isset($record[0]) && bin2hex($record[0])===$txid) {
        $blockHeight=(int)$record[1];
        $matched=false;
        $offset=0;
        while ($record[2]+$offset<=$TXO_table['bucket'][TOP]) {
            $txo=hashtable_read($TXO_table['bucket'],$record[2]+$offset);
            if ($txo[0]!==$index) {break;}
            $pub=hashtable_read($PUB_table['bucket'],$txo[3]);
            if (address_from_pubkeyhash($pub[0])===$address && (int)$txo[2]===$amountSats) {$matched=true;break;}
            $offset++;
        }
        if (!$matched) {
            return sendResponse($id,false,'OUTPUT_MISMATCH',$txid,[
                'address'=>$address,
                'amountSats'=>$amountSats,
                'confirmed'=>true,
                'blockHeight'=>$blockHeight,
                'confirmations'=>max(1,$height-$blockHeight),
                'height'=>max(0,$height-1),
                'blockHash'=>$lastBlockHash,
                'coreMs'=>0,
                'rotMs'=>(int)round((microtime(true)-$started)*1000)
            ]);
        }
        return sendResponse($id,true,'CONFIRMED',$txid,[
            'address'=>$address,
            'amountSats'=>$amountSats,
            'confirmed'=>true,
            'blockHeight'=>$blockHeight,
            'confirmations'=>max(1,$height-$blockHeight),
            'height'=>max(0,$height-1),
            'blockHash'=>$lastBlockHash,
            'coreMs'=>0,
            'rotMs'=>(int)round((microtime(true)-$started)*1000)
        ]);
    }

    $mempool=$RPC->callResult('getrawmempool',[]);
    if ($mempool['technical'] || !$mempool['ok'] || !is_array($mempool['result'])) {
        return sendResponse($id,false,'UNAVAILABLE',$txid,['technical'=>true,'address'=>$address,'amountSats'=>$amountSats,'error'=>'CORE_MEMPOOL_UNAVAILABLE','coreMs'=>$mempool['durationMs'],'rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    if (!in_array($txid,$mempool['result'],true)) {
        return sendResponse($id,true,'NOT_SEEN',$txid,['address'=>$address,'amountSats'=>$amountSats,'confirmed'=>false,'coreMs'=>$mempool['durationMs'],'rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }

    $decoded=$RPC->callResult('getrawtransaction',[$txid,1]);
    $coreMs=$mempool['durationMs']+$decoded['durationMs'];
    if ($decoded['technical'] || !$decoded['ok'] || !is_array($decoded['result']) || !isset($decoded['result']['txid']) || strtolower((string)$decoded['result']['txid'])!==$txid || !isset($decoded['result']['vout']) || !is_array($decoded['result']['vout'])) {
        return sendResponse($id,false,'UNAVAILABLE',$txid,['technical'=>true,'address'=>$address,'amountSats'=>$amountSats,'error'=>'CORE_TRANSACTION_UNAVAILABLE','coreMs'=>$coreMs,'rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    $matched=false;
    foreach ($decoded['result']['vout'] as $output) {
        if (!is_array($output) || !array_key_exists('value',$output)) {continue;}
        $outputAddress=coreP2pkhOutputAddress($output);
        if ($outputAddress===false) {continue;}
        $outputSats=coinValueToAtomicUnits($output['value']);
        if ($outputAddress===$address && $outputSats===$amountSats) {$matched=true;break;}
    }
    if (!$matched) {
        return sendResponse($id,false,'OUTPUT_MISMATCH',$txid,['address'=>$address,'amountSats'=>$amountSats,'confirmed'=>false,'coreMs'=>$coreMs,'rotMs'=>(int)round((microtime(true)-$started)*1000)]);
    }
    return sendResponse($id,true,'SEEN',$txid,['address'=>$address,'amountSats'=>$amountSats,'confirmed'=>false,'coreMs'=>$coreMs,'rotMs'=>(int)round((microtime(true)-$started)*1000)]);
}
function parseUnsignedHeight($value,&$parsed) {
    if (!is_string($value) || strlen($value)>10 || !preg_match('/^(0|[1-9][0-9]*)$/',$value)) {
        return false;
    }
    $parsed=(int)$value;
    return $parsed>=0 && (string)$parsed===$value;
}
function parsePubsParameters($params,&$parts,&$knownHeight,&$knownChanges,&$error) {
    $sections=explode(';',$params);
    $knownHeight=null;
    $knownChanges=null;
    if (count($sections)===1) {
        $parts=explode(',',$sections[0]);
        return true;
    }
    if (count($sections)!==3 || !parseUnsignedHeight($sections[0],$knownHeight)) {
        $error='INVALID_KNOWN_STATE';
        return false;
    }
    $parts=explode(',',$sections[1]);
    $markers=explode(',',$sections[2]);
    if (count($markers)!==count($parts)) {
        $error='INVALID_CHANGE_HEIGHTS';
        return false;
    }
    $knownChanges=[];
    foreach ($markers as $marker) {
        if ($marker==='-') {
            $knownChanges[]=null;
            continue;
        }
        $changeHeight=0;
        if (!parseUnsignedHeight($marker,$changeHeight) || $changeHeight>$knownHeight) {
            $error='INVALID_CHANGE_HEIGHTS';
            return false;
        }
        $knownChanges[]=$changeHeight;
    }
    return true;
}
function readPubsAddressState($address,$pubkeyhash,$index,array $record,&$outpoints,&$error) {
    global $height,$TX_table,$TXO_table;

    $addressBalance=0;
    $utxos=[];
    $lastChangeHeight=null;
    if ($index!==false && isset($record[0]) && $record[0]===$pubkeyhash) {
        $lastChangeHeight=(int)$record[1];
        $txoIndex=(int)$record[2];
        $visited=[];
        while (true) {
            if ($txoIndex<1 || $txoIndex>$TXO_table['bucket'][TOP] || isset($visited[$txoIndex])) {
                $error='CORRUPT_TXO_INDEX';
                return false;
            }
            $visited[$txoIndex]=true;
            $txo=hashtable_read($TXO_table['bucket'],$txoIndex);
            if (!isset($txo[3]) || $txo[3]!==$index || $txo[0]<1 || $txo[0]>$TX_table['bucket'][TOP]) {
                $error='CORRUPT_TXO_INDEX';
                return false;
            }
            $tx=hashtable_read($TX_table['bucket'],$txo[0]);
            $outpoint=bin2hex($tx[0]).':'.(int)$txo[1];
            if (isset($outpoints[$outpoint])) {
                $error='DUPLICATE_OUTPOINT';
                return false;
            }
            $outpoints[$outpoint]=true;
            if ($txo[4]===0) {
                $value=(int)$txo[2];
                $blockHeight=(int)$tx[1];
                $utxos[]=[
                    'txid'=>bin2hex($tx[0]),
                    'vout'=>(int)$txo[1],
                    'value'=>$value,
                    'height'=>$blockHeight,
                    'confirmations'=>max(1,$height-$blockHeight),
                    'scriptPubKey'=>'76a914'.bin2hex($pubkeyhash).'88ac'
                ];
                $addressBalance+=$value;
            }
            if ($txo[5]!==0) {
                $txoIndex=(int)$txo[5];
            } else {
                break;
            }
        }
    }
    return [
        'address'=>$address,
        'lastChangeHeight'=>$lastChangeHeight,
        'balance'=>$addressBalance,
        'utxos'=>$utxos
    ];
}
function handlePubsRequest($id,$params) {
    global $height,$lastBlockHash,$PUB_table,$versionByte,$tikker;

    if (!is_string($id) || $id==='' || strlen($id)>64) {
        return pubsError($id,'INVALID_ID');
    }

    $parts=[];
    $knownHeight=null;
    $knownChanges=null;
    $error='';
    if (!parsePubsParameters($params,$parts,$knownHeight,$knownChanges,$error)) {
        return pubsError($id,$error);
    }
    if (count($parts)<1 || count($parts)>MAX_PUBS) {
        return pubsError($id,'INVALID_ADDRESS_COUNT');
    }

    $addresses=[];
    $seen=[];
    foreach ($parts as $part) {
        $address=trim($part);
        if ($address==='' || isset($seen[$address])) {
            return pubsError($id,'INVALID_ADDRESS_SET');
        }
        $payload=base58check_decode($address);
        if ($payload===false || strlen($payload)!==21 || ord($payload[0])!==$versionByte) {
            return pubsError($id,'INVALID_COIN_ADDRESS');
        }
        $seen[$address]=true;
        $addresses[]=[
            'address'=>$address,
            'pubkeyhash'=>substr($payload,1,20)
        ];
    }

    $indexedHeight=max(0,$height-1);
    $delta=is_array($knownChanges);
    if ($delta && $indexedHeight<$knownHeight) {
        return pubsError($id,'STATE_BEHIND');
    }

    $totalBalance=0;
    $addressStates=[];
    $outpoints=[];
    foreach ($addresses as $position=>$item) {
        $address=$item['address'];
        $pubkeyhash=$item['pubkeyhash'];
        list($index,$record)=find($PUB_table,$pubkeyhash);
        $lastChangeHeight=($index!==false && isset($record[0]) && $record[0]===$pubkeyhash)?(int)$record[1]:null;
        if ($delta && $knownChanges[$position]===$lastChangeHeight) {
            continue;
        }
        $stateError='';
        $addressState=readPubsAddressState($address,$pubkeyhash,$index,$record,$outpoints,$stateError);
        if ($addressState===false) {return pubsError($id,$stateError);}
        $addressStates[]=$addressState;
        if (!$delta) {
            $totalBalance+=$addressState['balance'];
        }
    }

    $response=[
        'ok'=>true,
        'id'=>$id,
        'coin'=>$tikker,
        'mode'=>$delta?'delta':'full',
        'height'=>$indexedHeight,
        'blockHash'=>$lastBlockHash,
        'addresses'=>$addressStates
    ];
    if (!$delta) {
        $response['balance']=$totalBalance;
    }
    return encodePubsResponse($response);
}
function historyTimestampFromResult($result) {
    if (!is_array($result) || !isset($result['time']) || !is_int($result['time']) || $result['time']<1) {
        return false;
    }
    return $result['time'];
}
function historyBlockTimestamps(array $heights,&$error) {
    global $RPC;

    $timestamps=[];
    foreach (array_chunk($heights,500) as $heightChunk) {
        $calls=[];
        foreach ($heightChunk as $blockHeight) {$calls[]=['getblockhash',[$blockHeight]];}
        $hashResults=$RPC->batch($calls);
        ksort($hashResults,SORT_NUMERIC);
        $hashResults=array_values($hashResults);
        if (count($hashResults)!==count($heightChunk)) {$error='HISTORY_TIME_UNAVAILABLE';return false;}
        $headerCalls=[];
        foreach ($hashResults as $hash) {
            if ($hash instanceof \Exception || !is_string($hash) || !preg_match('/^[0-9a-fA-F]{64}$/',$hash)) {$error='HISTORY_TIME_UNAVAILABLE';return false;}
            $headerCalls[]=['getblockheader',[$hash,true]];
        }
        $headerResults=$RPC->batch($headerCalls);
        ksort($headerResults,SORT_NUMERIC);
        $headerResults=array_values($headerResults);
        $chunkTimestamps=[];
        $fallbackOffsets=[];
        if (count($headerResults)!==count($heightChunk)) {
            foreach ($heightChunk as $offset=>$blockHeight) {$fallbackOffsets[]=$offset;}
        } else {
            foreach ($headerResults as $offset=>$header) {
                $timestamp=historyTimestampFromResult($header);
                if ($timestamp===false) {
                    $fallbackOffsets[]=$offset;
                } else {
                    $chunkTimestamps[$offset]=$timestamp;
                }
            }
        }
        if (count($fallbackOffsets)>0) {
            $blockCalls=[];
            foreach ($fallbackOffsets as $offset) {$blockCalls[]=['getblock',[$hashResults[$offset]]];}
            $blockResults=$RPC->batch($blockCalls);
            ksort($blockResults,SORT_NUMERIC);
            $blockResults=array_values($blockResults);
            if (count($blockResults)!==count($fallbackOffsets)) {$error='HISTORY_TIME_UNAVAILABLE';return false;}
            foreach ($blockResults as $position=>$block) {
                $timestamp=historyTimestampFromResult($block);
                if ($timestamp===false) {$error='HISTORY_TIME_UNAVAILABLE';return false;}
                $chunkTimestamps[$fallbackOffsets[$position]]=$timestamp;
            }
        }
        foreach ($heightChunk as $offset=>$blockHeight) {
            if (!isset($chunkTimestamps[$offset])) {$error='HISTORY_TIME_UNAVAILABLE';return false;}
            $timestamps[$blockHeight]=$chunkTimestamps[$offset];
        }
    }
    return $timestamps;
}
function addHistoryEvent(&$events,$direction,$txid,$vout,$value,$address,$blockHeight) {
    if (count($events)>=MAX_HISTORY_EVENTS) {return false;}
    $events[]=[
        'direction'=>$direction,
        'txid'=>$txid,
        'vout'=>$vout,
        'value'=>$value,
        'address'=>$address,
        'height'=>$blockHeight
    ];
    return true;
}
function handleHistoryRequest($id,$params) {
    global $height,$lastBlockHash,$TX_table,$TXO_table,$PUB_table,$versionByte,$tikker;

    if (!is_string($id) || $id==='' || strlen($id)>64) {return pubsError($id,'INVALID_ID');}
    $parts=explode(',',$params);
    if (count($parts)<1 || count($parts)>MAX_PUBS) {return pubsError($id,'INVALID_ADDRESS_COUNT');}

    $addresses=[];
    $seen=[];
    $walletPubIds=[];
    foreach ($parts as $part) {
        $address=trim($part);
        if ($address==='' || isset($seen[$address])) {return pubsError($id,'INVALID_ADDRESS_SET');}
        $payload=base58check_decode($address);
        if ($payload===false || strlen($payload)!==21 || ord($payload[0])!==$versionByte) {return pubsError($id,'INVALID_COIN_ADDRESS');}
        $pubkeyhash=substr($payload,1,20);
        list($pubId,$record)=find($PUB_table,$pubkeyhash);
        if ($pubId!==false && isset($record[0]) && $record[0]===$pubkeyhash) {$walletPubIds[$pubId]=true;}
        $addresses[]=['address'=>$address,'pubId'=>$pubId,'record'=>$record,'pubkeyhash'=>$pubkeyhash];
        $seen[$address]=true;
    }

    $walletOutputs=[];
    $outgoingTransactions=[];
    $visitedOutputs=[];
    foreach ($addresses as $item) {
        if ($item['pubId']===false || !isset($item['record'][0]) || $item['record'][0]!==$item['pubkeyhash']) {continue;}
        $txoIndex=(int)$item['record'][2];
        $addressVisited=[];
        while (true) {
            if ($txoIndex<1 || $txoIndex>$TXO_table['bucket'][TOP] || isset($addressVisited[$txoIndex]) || isset($visitedOutputs[$txoIndex])) {return pubsError($id,'CORRUPT_TXO_INDEX');}
            $addressVisited[$txoIndex]=true;
            $visitedOutputs[$txoIndex]=true;
            $txo=hashtable_read($TXO_table['bucket'],$txoIndex);
            if (!isset($txo[3]) || $txo[3]!==$item['pubId'] || $txo[0]<1 || $txo[0]>$TX_table['bucket'][TOP]) {return pubsError($id,'CORRUPT_TXO_INDEX');}
            $tx=hashtable_read($TX_table['bucket'],$txo[0]);
            if (!isset($tx[0]) || strlen($tx[0])!==32 || $tx[1]<1 || $tx[1]>$height-1) {return pubsError($id,'CORRUPT_TX_INDEX');}
            if (count($walletOutputs)>=MAX_HISTORY_WALLET_OUTPUTS) {return pubsError($id,'HISTORY_TOO_LARGE');}
            $walletOutputs[]=[
                'txIndex'=>(int)$txo[0],
                'txid'=>bin2hex($tx[0]),
                'vout'=>(int)$txo[1],
                'value'=>(int)$txo[2],
                'address'=>$item['address'],
                'height'=>(int)$tx[1]
            ];
            if ($txo[4]!==0) {
                if ($txo[4]<1 || $txo[4]>$TX_table['bucket'][TOP]) {return pubsError($id,'CORRUPT_TX_INDEX');}
                $outgoingTransactions[(int)$txo[4]]=true;
                if (count($outgoingTransactions)>MAX_HISTORY_EVENTS) {return pubsError($id,'HISTORY_TOO_LARGE');}
            }
            if ($txo[5]===0) {break;}
            $txoIndex=(int)$txo[5];
        }
    }

    $events=[];
    foreach ($walletOutputs as $output) {
        if (isset($outgoingTransactions[$output['txIndex']])) {continue;}
        if (!addHistoryEvent($events,'IN',$output['txid'],$output['vout'],$output['value'],$output['address'],$output['height'])) {return pubsError($id,'HISTORY_TOO_LARGE');}
    }
    foreach ($outgoingTransactions as $txIndex=>$unused) {
        $tx=hashtable_read($TX_table['bucket'],$txIndex);
        if (!isset($tx[0]) || strlen($tx[0])!==32 || $tx[1]<1 || $tx[1]>$height-1 || $tx[2]<1 || $tx[2]>$TXO_table['bucket'][TOP]) {return pubsError($id,'CORRUPT_TX_INDEX');}
        $txid=bin2hex($tx[0]);
        $txoIndex=(int)$tx[2];
        $transactionVisited=[];
        $transactionOutputCount=0;
        while ($txoIndex<=$TXO_table['bucket'][TOP]) {
            if (isset($transactionVisited[$txoIndex])) {return pubsError($id,'CORRUPT_TXO_INDEX');}
            $transactionVisited[$txoIndex]=true;
            $transactionOutputCount++;
            if ($transactionOutputCount>MAX_HISTORY_EVENTS) {return pubsError($id,'HISTORY_TOO_LARGE');}
            $txo=hashtable_read($TXO_table['bucket'],$txoIndex);
            if ($txo[0]!==$txIndex) {break;}
            if ($txo[3]<1 || $txo[3]>$PUB_table['bucket'][TOP]) {return pubsError($id,'CORRUPT_TXO_INDEX');}
            if (!isset($walletPubIds[$txo[3]])) {
                $pub=hashtable_read($PUB_table['bucket'],$txo[3]);
                if (!isset($pub[0]) || strlen($pub[0])!==20) {return pubsError($id,'CORRUPT_TXO_INDEX');}
                $address=address_from_pubkeyhash($pub[0]);
                if (!addHistoryEvent($events,'OUT',$txid,(int)$txo[1],(int)$txo[2],$address,(int)$tx[1])) {return pubsError($id,'HISTORY_TOO_LARGE');}
            }
            $txoIndex++;
        }
    }

    usort($events,function($left,$right){
        if ($left['height']!==$right['height']) {return $left['height']<$right['height']?-1:1;}
        $txCompare=strcmp($left['txid'],$right['txid']);
        if ($txCompare!==0) {return $txCompare;}
        if ($left['vout']!==$right['vout']) {return $left['vout']<$right['vout']?-1:1;}
        return strcmp($left['direction'],$right['direction']);
    });
    $eventHeights=[];
    foreach ($events as $event) {$eventHeights[$event['height']]=true;}
    $timeError='';
    $timestamps=historyBlockTimestamps(array_keys($eventHeights),$timeError);
    if ($timestamps===false) {return pubsError($id,$timeError);}
    foreach ($events as &$event) {
        $event['timestamp']=$timestamps[$event['height']];
    }
    unset($event);

    return encodePubsResponse([
        'ok'=>true,
        'id'=>$id,
        'coin'=>$tikker,
        'height'=>max(0,$height-1),
        'blockHash'=>$lastBlockHash,
        'events'=>$events
    ]);
}
function handleClientRequest($request) {
    global $height,$TX_table,$TXO_table,$PUB_table,$versionByte;
    
    $start=microtime(true);
    $cmd=explode("|",trim($request));
    if (count($cmd)!=3) {  // ID|CMD|params
        return "3!\n";
    }
    $a=$cmd[1];$b=$cmd[2];
    $publicCommands=['stat','send','txstatus','zeroconf','history','pubs','pub','puball'];
    if (!in_array($a,$publicCommands,true)) {return "?\n";}
    $output="";
    if ($a=="stat") {
        $txSpace=number_format(100-100*$TX_table['bucket'][TOP]*$TX_table['bucket'][RECORDSIZE]/$TX_table['bucket'][SIZE],1,".","");
        $pubSpace=number_format(100-100*$PUB_table['bucket'][TOP]*$PUB_table['bucket'][RECORDSIZE]/$PUB_table['bucket'][SIZE],1,".","");
        $txoSpace=number_format(100-100*$TXO_table['bucket'][TOP]*$TXO_table['bucket'][RECORDSIZE]/$TXO_table['bucket'][SIZE],1,".","");
        $txSize=number_format($TX_table['bucket'][SIZE],0,".","");
        $pubSize=number_format($PUB_table['bucket'][SIZE],0,".","");
        $txoSize=number_format($TXO_table['bucket'][SIZE],0,".","");
        $output="Version:".VERSION."\n";
        $output.="Height:$height\n";
        $output.= "TX  size %free records: $txSize $txSpace {$TX_table['bucket'][TOP]}\n";
        $output.="PUB size %free records: $pubSize $pubSpace {$PUB_table['bucket'][TOP]}\n";
        $output.="TXO size %free records: $txoSize $txoSpace {$TXO_table['bucket'][TOP]}\n";
    } elseif ($a=="blk") {        
        if (!isValidIntString($b,$height)) {
            $output="$b:$height\n";
        } else {
            $n=$TX_table['bucket'][TOP];
            $found=false;
            for ($i=1;$i<=$n;$i++){
                $record=hashtable_read($TX_table['bucket'],$i);
                if ($record[1]==$b) {
                    $output.=bin2hex($record[0])."\n";
                    $found=true;
                } else {
                    if ($found) {break;}
                }
            }
            while ($i<$n) {
                $i++;
                $record=hashtable_read($TX_table['bucket'],$i);
                if ($record[1]==$b) {
                    $output.=bin2hex($record[0])."\n";
                } else {break;}
            }
        }
        $output.="(".(microtime(true)-$start).")\n";
    } elseif ($a=="audittest"){ // create audit test
        list($ip,$port)=explode(",",$b);
        $txRecord=rand(100,$TX_table['bucket'][TOP]-100);
        $pubRecord=rand(100,$PUB_table['bucket'][TOP]-100);
        $txoRecord=rand(100,$TXO_table['bucket'][TOP]-100);
        $data=shmop_read($TX_table['bucket'][P],$TX_table['bucket'][RECORDSIZE]*($txRecord-1),$TX_table['bucket'][RECORDSIZE]);
        $data.=shmop_read($PUB_table['bucket'][P],$PUB_table['bucket'][RECORDSIZE]*($pubRecord-1),$PUB_table['bucket'][RECORDSIZE]);
        $data.=shmop_read($TXO_table['bucket'][P],$TXO_table['bucket'][RECORDSIZE]*($txoRecord-1),$TXO_table['bucket'][RECORDSIZE]);
        $result=md5($data);
        $message="$txRecord,$pubRecord,$txoRecord,$result";
        $output="$message\n";
    } elseif ($a=="audit"){ // answer audit
        list($txRecord,$pubRecord,$txoRecord)=explode(",",$b);
        if (!filter_var($txRecord, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => $TX_table['bucket'][TOP]]])) {die();}
        if (!filter_var($pubRecord, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => $PUB_table['bucket'][TOP]]])) {die();}
        if (!filter_var($txoRecord, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => $TXO_table['bucket'][TOP]]])) {die();}
        $data=shmop_read($TX_table['bucket'][P],$TX_table['bucket'][RECORDSIZE]*($txRecord-1),$TX_table['bucket'][RECORDSIZE]);
        $data.=shmop_read($PUB_table['bucket'][P],$PUB_table['bucket'][RECORDSIZE]*($pubRecord-1),$PUB_table['bucket'][RECORDSIZE]);
        $data.=shmop_read($TXO_table['bucket'][P],$TXO_table['bucket'][RECORDSIZE]*($txoRecord-1),$TXO_table['bucket'][RECORDSIZE]);
        $result=md5($data);
        $output="$result\n";
    } elseif ($a=="testrich"){
        /* retrieve by (chrome)
           right-click "rank 1" + inspect
           right-click "<tbody> + copy INNERhtml
           paste this as A."/rich.dat"
           - Remember: coinbase transactions (mined inputs) and non-legacy outputs (non-PSPKH) are not indexed
           - The forth column is the pubtable index; retrieve it by sending the request pubtable:index
        */
        $html=file_get_contents(A."/rich.dat");
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress warnings for invalid HTML
        $dom->loadHTML($html);
        libxml_clear_errors();
        $rows = $dom->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length >= 5) {
                $col1 = trim($cells->item(0)->textContent); 
                $col2 = trim($cells->item(1)->textContent); 
                $col3 = trim($cells->item(2)->textContent); 
                $rich[]="$col1 | $col2 | $col3\n";
            }
        }

        $n=$PUB_table['bucket'][TOP];
        for ($i=1;$i<=$n;$i++){
            $record=hashtable_read($PUB_table['bucket'],$i);
            $short=substr(address_from_pubkeyhash($record[0]),0,8);
            $pubs[$short]=$i;
            file_put_contents(A."/short",$short."\n",FILE_APPEND);
            if (($i%10000)==0) {$output.="$i\n";}
        }
        foreach ($rich as $line) {
            $item=explode("|",trim($line));
            if (count($item)==3){
                $pub=substr(trim($item[1]),0,8);
                $sum=0;
                if (isset($pubs[$pub])) {
                    $record=hashtable_read($PUB_table['bucket'],$pubs[$pub]);
                    $txo=hashtable_read($TXO_table['bucket'],$record[2]);
                    while ($txo[3]==$pubs[$pub]){
                        if ($txo[4]==0) {$sum+=$txo[2];}
                        if ($txo[5]!=0){
                            $txo=hashtable_read($TXO_table['bucket'],$txo[5]);
                        } else {
                            $txo[3]=0;
                        }
                    }
                    $output.=trim($line)." | {$pubs[$pub]} | $sum\n";
                } else { 
                    $output.=trim($line)." | ?\n";
                }
            }
        }            
    } elseif ($a=="tx"){
        [$index,$record]=find($TX_table,hex2bin($b));
        if ($index){
            $output.="\n{$cmd[0]}";
            $output.= "index:$index\n";
            $output.= "txId:".bin2hex($record[0])."\n";
            $output.= "block:{$record[1]}\n";
            $output.= "txo-pointer:{$record[2]}\n";
            $output.= "collision:{$record[3]}\n";
            $txo=hashtable_read($TXO_table['bucket'],$record[2]);
            $i=0;
            while ($txo[0]==$index) {
                $pub=hashtable_read($PUB_table['bucket'],$txo[3]);
                $base58=address_from_pubkeyhash($pub[0]);
                $output.= "n:{$txo[1]} value:{$txo[2]} pub:$base58 spend:{$txo[4]} linked-list:{$txo[5]}\n";
                $i++;
                $txo=hashtable_read($TXO_table['bucket'],$record[2]+$i);                    
            }
        }
        $output.= "(".(microtime(true)-$start).")\n\n";            
    } elseif ($a=="send"){
        return handleSendRequest($cmd[0],$b);
    } elseif ($a=="txstatus"){
        return handleTransactionStatusRequest($cmd[0],$b);
    } elseif ($a=="zeroconf"){
        return handleZeroConfirmationRequest($cmd[0],$b);
    } elseif ($a=="history"){
        return handleHistoryRequest($cmd[0],$b);
    } elseif ($a=="pubs"){
        return handlePubsRequest($cmd[0],$b);
    } elseif ($a=="pub"){
        $payload=base58check_decode($b);
        if ($payload) {
            $pubkeyhash=substr($payload,1);
            [$index,$record]=find($PUB_table,$pubkeyhash);
            if ($record[0]==$pubkeyhash) {
                $output.= "\n{$cmd[0]}";
                $output.= "\nversion:$versionByte\n";
                $output.= "\npkhash:".bin2hex($record[0]);
                $output.= "\nblocknr/Lastchange:".$record[1];
                $output.= "\nfirst txo:".$record[2];
                $output.= "\nlast txo:".$record[3];
                $txo=hashtable_read($TXO_table['bucket'],$record[2]);
                $sum=0;$n=0;
                while ($txo[3]==$index){
                    $n++;
                    if ($txo[4]==0) {
                        $tx=hashtable_read($TX_table['bucket'],$txo[0]);
                        $output.= "\n".bin2hex($tx[0]).":".$txo[1].":".$txo[2];
                        $sum+=$txo[2];
                    }
                    if ($txo[5]!=0){
                        $txo=hashtable_read($TXO_table['bucket'],$txo[5]);
                    } else {
                        $txo[3]=0;
                    }
                }                    
                $output.= "\ntxo total:$n";                            
                $output.= "\nbalance:$sum\n";
            }
        }
        $output.= "(".(microtime(true)-$start).")\n\n";
    } elseif ($a=="puball"){
        $payload=base58check_decode($b);
        if ($payload) {
            $pubkeyhash=substr($payload,1);
            [$index,$record]=find($PUB_table,$pubkeyhash);
            if ($record[0]==$pubkeyhash) {
                $output.= "\n{$cmd[0]}";
                $output.= "\npkhash:".bin2hex($record[0]);
                $output.= "\nblocknr/Lastchange:".$record[1];
                $output.= "\nfirst txo:".$record[2];
                $output.= "\nlast txo:".$record[3];
                $txo=hashtable_read($TXO_table['bucket'],$record[2]);
                $sum=0;$sum_in=0;$sum_out=0;$n=0;$n_in=0;$n_out=0;
                while ($txo[3]==$index){
                    $n++;
                    $sum+=$txo[2];
                    if ($txo[4]==0) {
                        $tx=hashtable_read($TX_table['bucket'],$txo[0]);
                        $output.= "\n".$n.":".bin2hex($tx[0]).":".$txo[1].":".$txo[2];
                        $sum_in+=$txo[2];
                        $n_in++;
                    }else{
                        $tx=hashtable_read($TX_table['bucket'],$txo[4]);
                        $output.= "\n".$n.":".bin2hex($tx[0]).":".$txo[1].":".$txo[2];
                        $sum_out+=$txo[2];
                        $n_out++;
                    }
                    if ($txo[5]!=0){
                        $txo=hashtable_read($TXO_table['bucket'],$txo[5]);
                    } else {
                        $txo[3]=0;
                    }
                }                    
                $output.= "\ntxo total:$n spend:$n_out rest:$n_in";
                $output.= "\nInput:$sum spend:$sum_out rest:$sum_in\n";
            }
        }
        $output.= "(".(microtime(true)-$start).")\n\n";
    } elseif ($a=="txtable") {
        if ($b==""){$output.=print_r($TX_table,true);
        }elseif ($b=="performance") {
            $n=$TX_table['bucket'][TOP];
            for ($i=1;$i<=$n;$i++){$record=hashtable_read($TX_table['bucket'],$i);}
        }elseif (is_numeric($b)) {
            $record = hashtable_read($TX_table['bucket'],$b);
            $output.=print_r($record,true);
            $output.= bin2hex($record[0])."\n";
        }
        $output.= "(".(microtime(true)-$start).")\n\n";
    } elseif ($a=="txotable") {
        if ($b==""){print_r($TXO_table);
        }elseif ($b=="performance") {
            $n=$TXO_table['bucket'][TOP];
            for ($i=1;$i<=$n;$i++){$record=hashtable_read($TXO_table['bucket'],$i);}
        }elseif (is_numeric($b)) {
            $record = hashtable_read($TXO_table['bucket'],$b);
            $output.=print_r($record,true);
        }
        $output.= "(".(microtime(true)-$start).")\n\n";
    } elseif ($a=="pubtable") {
        if ($b==""){$output.=print_r($PUB_table,true);
        }elseif ($b=="performance") {
            $n=$PUB_table['bucket'][TOP];
            for ($i=1;$i<=$n;$i++){$record=hashtable_read($PUB_table['bucket'],$i);}
        }elseif (is_numeric($b)) {
            $record = hashtable_read($PUB_table['bucket'],$b);
            $output.= "scripthash:".bin2hex($record[0])."\n";
            $output.= "base58:".address_from_pubkeyhash($record[0])."\n";
            $output.= "blocknr last change:{$record[1]}\n";
            $output.= "first txo:{$record[2]}\n";
            $output.= "last txo:{$record[3]}\n";
            $output.= "collision:{$record[4]}\n";
        }
        $output.= "(".(microtime(true)-$start).")\n\n";
    }elseif ($a=="stop") {
        die("\n");
    }
    if ($output=="") {
        return "?$request\n";
    }else{
        return $output;        
    }
}
function findBlok($block) {
    global $TX_table,$height;
    $start=microtime(true);$iterations=0;
    $i=0;
    $n=$TX_table['bucket'][TOP];
    $record1 = hashtable_read($TX_table['bucket'],1);
echo "1:{$record1[1]};";
    $record2 = hashtable_read($TX_table['bucket'],$n);
echo "$n:{$record2[1]};";
    $diff=$record2[1]-$record1[1];
    $step=$diff/$n;
    $direction=1;
    $last=$record1;
    $absent=false;
    while ($diff!=0) {
        $iterations++;
        $i = max(1, min($n, $i + $direction * floor($block * $step)));

        $record = hashtable_read($TX_table['bucket'],$i);
echo "$i:{$record[1]}";
        $diff=$record[1]-$block;
        if ($diff!=0) {
            if ($step==1) {
                if ($direction==1) {
                    if ($record[1]>$block) {$absent=true;break;} // block absent
                } else {
                    if ($record[1]<$block) {$absent=true;break;} // block absent
                }
            }
            if ($diff<0) {
                $direction=1;
            } else {
                $direction=-1;
                if ($step>1) {$step--;}
            }
        } 
        $last=$record[1];
        if ($iterations>200) {break;}
    }
    if (!$absent) {
        do {
            $i--;
            $record = hashtable_read($TX_table['bucket'],$i);
        } while ($record[1]!=$block);
        do {
            $i++;
            $record = hashtable_read($TX_table['bucket'],$i);
            if ($record[1]==$block) $tx[]=bin2hex($record[0]);
        } while ($record[1]==$block);
        echo (microtime(true)-$start).":$iterations\n";
        print_r($tx); // <------------- rubbish
    } else {
        echo (microtime(true)-$start).":$iterations\nBlock has no relevant transactions; close:$last\n";
    }
}

class BlockIndex { /* loads all blockhashes through RPC;
   Use it to serialize blocks in blk*.dat (which are not serialized)
   Buffer previously loaded hashes in file blockhashes
*/
    public $hashMap = [];
    public $maxReorgDepth=100;
    public $backupHeight;
    public $tip;
    private $batchSize=500;
    
    public function __construct() {
        L("Test if blockchain is synced...");
        try {
            $this->awaitSync();
            L("\n");
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            L("\nCannot reach core: ".now()." ". $e->getMessage()."\n");
            die();
        }        
        $start=0;
        if (file_exists(DATA."blockhashes")){
            $blockhashes=explode("\n",file_get_contents(DATA."blockhashes"));
            $start=count($blockhashes)-1;
            for ($i=0; $i<$start; $i++){$this->hashMap[$blockhashes[$i]]=$i;}
        }
        $fetched=$this->fetchBlockhashesFromRpc($start, $this->batchSize);
        file_put_contents(DATA."blockhashes",$fetched,FILE_APPEND);
        $blockhashes=explode("\n",$fetched);
        for ($i=0; $i<count($blockhashes); $i++){$this->hashMap[$blockhashes[$i]]=$i+$start;}
        $this->tip=count($blockhashes)+$start-2;
        $this->backupHeight=$this->tip-$this->maxReorgDepth;
        L(count($blockhashes)." new blocks; Latest: {$this->tip}\n");
    }
    private function fetchBlockhashesFromRpc(int $start, int $batchSize): string {
        global $RPC;
        $fetched = '';
        $height = $start;
        L("Loading block hashes (batch size: $batchSize) ...");
        $end = null;
        $round=0;$rounds=round(100000/$batchSize);
        while (true) {
            if ($end !== null && $height > $end) break;
            $count = ($end !== null && ($end - $height + 1) < $batchSize) ? ($end - $height + 1) : $batchSize;
            $batch = [];
            for ($i = $height; $i < $height + $count; $i++) {
                $batch[] = ['getblockhash', [$i]];
            }
            $results = $RPC->batch($batch);
            $i=0;
            foreach ($results as $id => $result) {
                if ($result instanceof \Exception) {
                    L(" .".($height+$i)."\n");    
                    return $fetched; // likely done
                }
                $fetched .= $result . "\n";
                $i++;
            }
            $height += $count;
            $round++;
            echo("\rLoading block hashes (batch size: $batchSize) ... $height");
        }
        L(" $height\n");
        return $fetched;
    }
    private function awaitSync(): void {        
        global $RPC;
        $blockTip=[];
        $once=true;
        while (true) {
            $tipHash   = $RPC->call('getbestblockhash', []);
            if ($tipHash!==null) {break;}
        }
        while (true) {
            while (true) {
                $tipHash   = $RPC->call('getbestblockhash', []);
                if ($tipHash!==null) {break;}
            }
            while (true) {
                $blockTip = $RPC->call('getblock', [$tipHash]);
                if ($blockTip!==null) {break;}
            }
            $lag       = time() - $blockTip['time'];
            if ($lag < 3600) {break;} else {if ($once) {$once=false;L("Waiting for Core to sync...");}}
            sleep(60);
        }
    }
}

class BlockParser { /* Unravels a binary block */
    public function getBlock($buffer): array {
        global $height,$RPC;
        $skipped=0;        
        $transactions = [];
        if (is_array ($buffer)) { // decoded json
            $txCount=count($buffer['tx']);
            for ($i = 0; $i < $txCount; $i++) {
                $txid=$buffer['tx'][$i];
                $txBin=hex2bin($RPC->call('getrawtransaction', [$txid, 0]));
                $offset=0;
                $tx = $this->parseTransactionLite($txBin, $offset);                
                if ($tx['is_relevant']) {$transactions[] = $tx;} else {$skipped++;}
                $offset += $tx['length'];
            }
        } else {
            $offset = 0;
            $version = unpack("V", substr($buffer, $offset, 4))[1];
            $offset += 4;    
            $prevBlock = strrev(substr($buffer, $offset, 32));
            $offset += 32;
            $merkleRoot = strrev(substr($buffer, $offset, 32));
            $offset += 32;
            $timestamp = unpack("V", substr($buffer, $offset, 4))[1];
            $offset += 4;
            $bits = unpack("V", substr($buffer, $offset, 4))[1];
            $offset += 4;
            $nonce = unpack("V", substr($buffer, $offset, 4))[1];
            $offset += 4;
            $header = [
                'version' => $version,
                'prevBlock' => bin2hex($prevBlock),
                'merkleRoot' => bin2hex($merkleRoot),
                'timestamp' => $timestamp,
                'bits' => $bits,
                'nonce' => $nonce
            ];
            $offset = 80; // Skip block header just for clarity
            if ($version & (1 << 8)) {$this->skipAuxPowHeader($buffer, $offset);}
            
            $txCountSize = 0;
            $txCount = $this->parseVarInt($buffer, $offset, $txCountSize);
            $offset += $txCountSize;    
            for ($i = 0; $i < $txCount; $i++) {
                $tx = $this->parseTransactionLite($buffer, $offset);
                $tx['original']=$i; // to track skipped transactions; not used currently
                $tx['offset']=$offset;
                if ($tx['is_relevant']) {$transactions[] = $tx;} else {$skipped++;}
                $offset += $tx['length'];
            }
        }
        return [
            'txCount' => $txCount,
            'transactions' => $transactions,
            'skipped' => $skipped
        ];
    }
    private function parseTransactionLite(string $buffer, int $offset): array {
        global $height,$tikker;
        /* Ring-of-trust-only parser
           Marks a transaction as relevant when it must be indexed; skips coinbase and tx that don't have P2PKH outputs
        */
        $startOffset = $offset;
    
        // 1. Version (4 bytes)
        $version = substr($buffer, $offset, 4);
        $offset += 4;
        
        if ($tikker=="DEM") {$offset += 4;} //nTime
    
        // 2. Detect SegWit Marker/Flag (peek)
        $marker = ord($buffer[$offset] ?? "\x00");
        $flag   = ord($buffer[$offset + 1] ?? "\x00");
    
        $hasSegWitMarker = ($marker === 0x00);
        $hasSegWitFlag   = ($flag & 0x01) !== 0;  // ignore MWEB 0x08
        $isSegWit        = false;
        if ($hasSegWitMarker) {
            $offset += 2; // Always skip marker/flag if marker is 0x00
            $isSegWit = $hasSegWitFlag;
        }
    
        // 3. Parse inputs (vin)
        $vinCountOffset = $offset;
        $vinCountLen = 0;
        $vinCount = $this->parseVarInt($buffer, $offset, $vinCountLen);
        $offset += $vinCountLen;
        $vinStart=$offset;
        $inputs=[];
        for ($i = 0; $i < $vinCount; $i++) {
            if ($offset>strlen($buffer)){
                die ("break at $height\n");
            }
            
            $inputs[]=$this->parseInput($buffer, $offset);
        }
    
        // 4. Coinbase
        $isCoinbase = (
            $vinCount === 1 &&
            substr($buffer, $vinStart, 32) === str_repeat("\x00", 32)
        );

        // 5. Parse outputs (vout)
        $voutCountLen = 0;
        $voutCount = $this->parseVarInt($buffer, $offset, $voutCountLen);
        $voutStart=$offset;    
        $offset += $voutCountLen;
        $outputs=[];
        for ($i = 0; $i < $voutCount; $i++) {
            $output=$this->parseOutput($buffer, $offset);
            if ($output[1]!=false) {$outputs[]=[$i,$output[0],$output[1]];} // n,value (P),hash; you could trace OP_RETURNS here using n=-1 and output[0]
        }
        $outputsEnd = $offset;
        
        // 6. If SegWit, parse witness for each input
        if ($isSegWit) {
            for ($i = 0; $i < $vinCount; $i++) {
                $this->parseWitness($buffer, $offset);
            }
        }
        
        // x. MWEB ignore (would be at least one byte)
        
        // 7. Locktime (4 bytes)
        $locktime = substr($buffer, $offset, 4);
        $offset += 4;
        
        // 8. Other stuff
        if ($tikker=="DEM"){
            $commentLength = $this->readVarInt($buffer, $offset);
            $offset += $commentLength;
        }

        $isRelevant=true;
        if ($isCoinbase){
            $isRelevant=false;
        } else {
            if (count($outputs)==0){$isRelevant=false;}
        }
        if ($isRelevant) {
            if ($isSegWit) {
                $preInputsLen = $vinStart - $startOffset;
                $inputsLen = $voutStart - $vinStart;
                $locktimeLen = 4;
                $txBytes =
                    substr($buffer, $startOffset, 4) . // version
                    substr($buffer, $vinCountOffset, $outputsEnd - $vinCountOffset) . // vinCount + inputs + voutCount + outputs
                    $locktime;
            } else {
                $txBytes=substr($buffer,$startOffset,$offset - $startOffset);  // Legacy
            }
            $txid = bin2hex(strrev(hash('sha256', hash('sha256', $txBytes, true), true)));
        } else {
            $txid = null;
        }
        return [
            'length' => $offset - $startOffset,
            'is_relevant' => $isRelevant,
            'is_segwit' => $isSegWit,
            'is_coinbase' => $isCoinbase,
            'txid' => $txid,
            'outputs' => $outputs,
            'inputs' => $inputs
        ];
    }
    private function skipAuxPowHeader(string $buffer, int &$offset) {
        // 1. Skip embedded coinbase tx (AuxPoW coinbasetx)
        $this->skipTransaction($buffer, $offset);
        
        $offset += 32;  // parent block hash?????
        
        $coinbaseBranchCount = $this->readVarInt($buffer, $offset);
        $offset += 32 * $coinbaseBranchCount;
    
        $coinbaseIndex = substr($buffer, $offset, 4);
        $offset += 4;
    
        // 4. Now at chainMerkleBranch
        $chainBranchCount = $this->readVarInt($buffer, $offset);
        $offset += 32 * $chainBranchCount;
    
        // 5. chainIndex (4 bytes)
        $offset += 4;
    
        // 6. parent block header (80 bytes)
        $offset += 80;
    }    
    private function skipTransaction(string $buffer, int &$offset): void {
        // 1. version (4 bytes)
        $offset += 4;
    
        // 2. inputs (vin)
        $vinCount = $this->readVarInt($buffer, $offset);
        for ($i = 0; $i < $vinCount; $i++) {
            $offset += 32; // prev txid
            $offset += 4;  // prev vout index
    
            $scriptLen = $this->readVarInt($buffer, $offset);
            $offset += $scriptLen; // scriptSig
    
            $offset += 4; // sequence
        }
    
        // 3. outputs (vout)
        $voutCount = $this->readVarInt($buffer, $offset);
        for ($i = 0; $i < $voutCount; $i++) {
            $offset += 8; // value
    
            $scriptLen = $this->readVarInt($buffer, $offset);
            $offset += $scriptLen; // scriptPubKey
        }
    
        // 4. locktime (4 bytes)
        $offset += 4;
    }    
    private function parseVarInt(string $buffer, int $offset, &$size): int {
        global $height,$parseContext;
        $first = ord($buffer[$offset]);
        if ($first < 0xfd) {
            $size = 1;
            return $first;
        } elseif ($first === 0xfd) {
            $size = 3;
            return unpack("v", substr($buffer, $offset + 1, 2))[1];
        } elseif ($first === 0xfe) {
            $size = 5;
            return unpack("V", substr($buffer, $offset + 1, 4))[1];
        } else {
            $size = 9;
            return unpack("P", substr($buffer, $offset + 1, 8))[1];
        }
    }
    function readVarInt($buffer, &$offset) {
        $first = ord($buffer[$offset++]);
        if ($first < 0xfd) return $first;
        if ($first === 0xfd) {
            $val = unpack("v", substr($buffer, $offset, 2))[1];
            $offset += 2;
            return $val;
        }
        if ($first === 0xfe) {
            $val = unpack("V", substr($buffer, $offset, 4))[1];
            $offset += 4;
            return $val;
        }
        $val = unpack("P", substr($buffer, $offset, 8))[1]; // Little-endian 64-bit
        $offset += 8;
        return $val;
    }
    private function parseVarBytes($buffer, &$offset) {
        $len = $this->readVarInt($buffer, $offset);
        $data = substr($buffer, $offset, $len);
        $offset += $len;
        return [$data, $len];
    }
    private function parseInput(string $buffer, int &$offset) {
        $prev_tx=substr($buffer,$offset,32);
        $prev_vout=unpack("V",substr($buffer,$offset+32,4))[1];
        $offset += 32 + 4;
        $this->parseScript($buffer, $offset);
        $offset += 4;
        return ([$prev_tx,$prev_vout]);
    }
    private function parseOutput(string $buffer, int &$offset) {
        $value=unpack("P",substr($buffer,$offset,8))[1];
        $offset += 8;
        $scriptLenLen = 0;
        $scriptLen = $this->parseVarInt($buffer, $offset, $scriptLenLen);
        $offset += $scriptLenLen;
        $script = substr($buffer, $offset, $scriptLen);
        $b0 = ord($script[0] ?? "\x00");
        if (($b0 === 0x76) &&
            ((ord($script[1] ?? "\x00") === 0xa9) &&
            (ord($script[2] ?? "\x00") === 0x14) &&
            (strlen($script) === 25) &&
            (ord($script[23] ?? "\x00") === 0x88) &&
            (ord($script[24] ?? "\x00") === 0xac))) {
            $pubKeyHash = substr($script, 3, 20);
        } else {
            $pubKeyHash = false;
        }
        $offset += $scriptLen;
        return [$value,$pubKeyHash];
    }
    private function parseScript(string $buffer, int &$offset): void {
        $scriptSizeLen = 0;
        $scriptLen = $this->parseVarInt($buffer, $offset, $scriptSizeLen);
        $offset += $scriptSizeLen + $scriptLen;
    }
    private function parseWitness(string $buffer, int &$offset): void {
        $itemCountLen = 0;
        $itemCount = $this->parseVarInt($buffer, $offset, $itemCountLen);
        $offset += $itemCountLen;
    
        for ($i = 0; $i < $itemCount; $i++) {
            $itemLenLen = 0;
            $itemLen = $this->parseVarInt($buffer, $offset, $itemLenLen);
            $offset += $itemLenLen;
            $offset += $itemLen;
        }
    }
    private function parseOutputSec(string $buffer, int &$offset): void { // parse-only
        $offset += 8;
        $scriptLenLen = 0;
        $scriptLen = $this->parseVarInt($buffer, $offset, $scriptLenLen);
        $offset += $scriptLenLen;
        $offset += $scriptLen;
    }
    private function parseOutputGeneric(string $buffer, int &$offset, array &$scriptPubKeyHashes, array &$opReturnData): void {
        /* For later use (if OP_RETURN becomes relevant)
         * Parse one output, advancing $offset, and categorize:
         *  - P2PKH/P2SH/P2WPKH/etc  add hash160(scriptPubKey) to $scriptPubKeyHashes
         *  - OP_RETURN              extract OP_RETURN payload into $opReturnData
         *
         */
        
        $offset += 8; // 1) Skip value (8 bytes)
    
        // 2) Read script length (varint)
        $scriptLenLen = 0;
        $scriptLen = $this->parseVarInt($buffer, $offset, $scriptLenLen);
        $offset += $scriptLenLen;
    
        // 3) Extract the full scriptPubKey
        $script = substr($buffer, $offset, $scriptLen);
    
        // 4) Categorize
        $b0 = ord($script[0] ?? "\x00");
        if ($b0 === 0x6a) {
            // OP_RETURN
            $pos = 1;
            $payloads = [];
    
            while ($pos < $scriptLen) {
                $op = ord($script[$pos]);
                $pos++;
    
                if ($op >= 1 && $op <= 75) {
                    // OP_PUSHBYTES_n: next 'n' bytes are payload
                    $n = $op;
                } elseif ($op === 0x4c) {
                    // OP_PUSHDATA1: next byte is length
                    $n = ord($script[$pos]);
                    $pos++;
                } elseif ($op === 0x4d) {
                    // OP_PUSHDATA2: next two bytes LE
                    $n = unpack('v', substr($script, $pos, 2))[1];
                    $pos += 2;
                } else {
                    // Other OP_* inside OP_RETURN, skip or break
                    break;
                }
    
                // slice out the data
                $data = substr($script, $pos, $n);
                $payloads[] = bin2hex($data);
                $pos += $n;
            }
    
            // record the OP_RETURN payload(s)
            $opReturnData[] = $payloads;
    
        } elseif (($b0 === 0x76) &&
            ((ord($script[1] ?? "\x00") === 0xa9) &&
            (ord($script[2] ?? "\x00") === 0x14) &&
            (strlen($script) === 25) &&
            (ord($script[23] ?? "\x00") === 0x88) &&
            (ord($script[24] ?? "\x00") === 0xac))) {
            $pubKeyHash = substr($script, 3, 20);
        } else {    
            // non-OP_RETURN: hash160(script)
            $hash160 = hash('ripemd160', hash('sha256', $script, true), true);
            $scriptPubKeyHashes[] = bin2hex($hash160);
        }    
        $offset += $scriptLen;
    } 
}
function base58check_decode($base58) {
    global $alphabet;
    $base58chars = str_split($base58);

    // 1. Decode base58 naar bytes-array (grote-endian)
    $bytes = [0];
    foreach ($base58chars as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) return false;
        // $bytes = $bytes * 58 + $pos
        $carry = $pos;
        for ($i = 0; $i < count($bytes); $i++) {
            $carry += $bytes[$i] * 58;
            $bytes[$i] = $carry & 0xFF; // % 256
            $carry >>= 8; // floor($carry / 256)
        }
        while ($carry > 0) {
            $bytes[] = $carry & 0xFF;
            $carry >>= 8;
        }
    }
    // De bytes zijn nu little-endian, omdraaien voor verder gebruik
    $bin = '';
    foreach (array_reverse($bytes) as $b) {
        $bin .= chr($b);
    }

    // 2. Leading '1's in base58 zijn \x00 bytes
    $pad = 0;
    for ($i = 0; $i < strlen($base58) && $base58[$i] === '1'; $i++) $pad++;
    $bin = str_repeat("\x00", $pad) . $bin;

    // 3. Check minimaal 4 bytes (checksum)
    if (strlen($bin) < 4) return false;

    $payload = substr($bin, 0, -4);
    $checksum = substr($bin, -4);
    $hash = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

    if ($checksum !== $hash) return false;
    return $payload;
}
function pubkeyhash_from_base58_address($address) {
    $decoded = base58check_decode($address);
    if ($decoded === false || strlen($decoded) !== 21) {
        return false;
    }

    $version = ord($decoded[0]); // e.g. 0x1C for Komodo P2PKH
    $pubkeyHash = substr($decoded, 1, 20); // binary

    return bin2hex($pubkeyHash);
}
function address_from_pubkeyhash(string $pubkeyHash) {
    global $versionByte;
    if ($pubkeyHash === false || strlen($pubkeyHash) !== 20) {
        return false;
    }
    $data = chr($versionByte) . $pubkeyHash; // 21 bytes
    $checksum = substr(hash('sha256', hash('sha256', $data, true), true), 0, 4);
    $payload = $data . $checksum;        // 25 bytes
    return base58_encode($payload);
}
function base58_encode(string $bin): string {
    global $alphabet;

    // Convert binary data to an array of byte values
    $bytes = array_map('ord', str_split($bin));

    $result = '';
    // While there are still non-zero bytes
    while (count($bytes) > 0) {
        $carry = 0;
        $newBytes = [];
        foreach ($bytes as $b) {
            // acc = carry * 256 + b
            $acc = ($carry << 8) + $b;
            // quo = acc / 58, rem = acc % 58
            $quo = intdiv($acc, 58);
            $carry = $acc % 58;
            // skip leading zeros in newBytes
            if (count($newBytes) > 0 || $quo !== 0) {
                $newBytes[] = $quo;
            }
        }
        // carry is remainder ? next Base58 digit
        $result = $alphabet[$carry] . $result;
        $bytes = $newBytes;
    }

    // Add 1 for each leading 0x00 byte in input
    foreach (str_split($bin) as $ch) {
        if ($ch === "\x00") {
            $result = $alphabet[0] . $result;
        } else {
            break;
        }
    }

    return $result;
}
class JsonRpcClient {
    private $url;
    private $id = 0;
    private $repeat_error = 0;
    private $last_error   = "";

    public function __construct(array $rpc) {
        $this->url = "http://{$rpc['user']}:{$rpc['pass']}@{$rpc['host']}:{$rpc['port']}/";
    }
    public function call($method, $params = []) {
        $payload = json_encode([
            'method' => $method,
            'params' => $params,
            'id'     => 1
        ]);

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 2,
        ]);

        $response = curl_exec($ch);
                //    if (strpos($e->getMessage(),'"code":-8')===false) {
                //    if (strpos($e->getMessage(),'"code":-1')===false) {
        if ($response === false) {
            return $this->handleError("CURL error: " . curl_error($ch));
        }
        $decoded = json_decode($response, true);
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            $err = $decoded['error'];
            if (in_array($err['code'], [-8, -1])) { // Expected "not ready yet"
                return $err['code'];
            }
            return $this->handleError("RPC error: " . json_encode($decoded['error']));
        }
        $this->repeat_error = 0;
        $this->last_error   = "";
        return $decoded['result'];
    }
    public function callResult($method,$params=[]) {
        $started=microtime(true);
        $payload=json_encode([
            'method'=>$method,
            'params'=>$params,
            'id'=>1
        ]);
        if ($payload===false) {
            return ['technical'=>true,'ok'=>false,'error'=>'CORE_REQUEST_ENCODE_FAILED','durationMs'=>0];
        }

        $ch=curl_init($this->url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>6
        ]);
        $response=curl_exec($ch);
        $httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($response===false) {
            curl_close($ch);
            return ['technical'=>true,'ok'=>false,'error'=>'CORE_RPC_UNAVAILABLE','durationMs'=>(int)round((microtime(true)-$started)*1000)];
        }
        curl_close($ch);

        $decoded=json_decode($response,true);
        $durationMs=(int)round((microtime(true)-$started)*1000);
        if (!is_array($decoded) || (!array_key_exists('result',$decoded) && !array_key_exists('error',$decoded))) {
            return ['technical'=>true,'ok'=>false,'error'=>'INVALID_CORE_RESPONSE','durationMs'=>$durationMs];
        }
        if ($httpCode!==0 && $httpCode!==200 && $httpCode!==500) {
            return ['technical'=>true,'ok'=>false,'error'=>'CORE_HTTP_ERROR','durationMs'=>$durationMs];
        }
        if (isset($decoded['error']) && $decoded['error']!==null) {
            $rpcCode=isset($decoded['error']['code'])?(int)$decoded['error']['code']:0;
            $rpcMessage=isset($decoded['error']['message'])?(string)$decoded['error']['message']:'Core rejected the request';
            if ($rpcCode===-28) {
                return ['technical'=>true,'ok'=>false,'error'=>'CORE_NOT_READY','durationMs'=>$durationMs];
            }
            return ['technical'=>false,'ok'=>false,'rpcCode'=>$rpcCode,'rpcMessage'=>substr($rpcMessage,0,512),'durationMs'=>$durationMs];
        }
        if ($httpCode!==0 && $httpCode!==200) {
            return ['technical'=>true,'ok'=>false,'error'=>'CORE_HTTP_ERROR','durationMs'=>$durationMs];
        }
        return ['technical'=>false,'ok'=>true,'result'=>$decoded['result'],'durationMs'=>$durationMs];
    }
    private function handleError($msg) {
        if ($this->last_error !== $msg) {
            L("Caught exception: " . $msg);
            $this->last_error   = $msg;
            $this->repeat_error = 0;
        } else {
            if ($this->repeat_error < 60) {
                $this->repeat_error++;
            }
            L(".");
        }
        if ($this->repeat_error > 10) {
            sleep($this->repeat_error);
        } else {
            sleep(1);
        }
        return null;
    }
    public function batch(array $calls): array {
        $batch = [];
        $ids = [];
        foreach ($calls as [$method, $params]) {
            $payload = $this->makePayload($method, $params);
            $batch[] = $payload;
            $ids[$payload['id']] = $method;
        }
        try {
            $responses=$this->sendRequest($batch);
        } catch (\Exception $exception) {
            $results=[];
            foreach ($ids as $id=>$method) {$results[$id]=$exception;}
            return $results;
        }
        // Match responses by ID
        $results = [];
        foreach ($responses as $res) {
            $id = $res['id'] ?? null;
            if (isset($res['error']) && $res['error'] !== null) {
                $results[$id] = new \Exception("RPC error: " . json_encode($res['error']));
            } else {
                $results[$id] = $res['result'] ?? null;
            }
        }
        return $results;
    }
    private function makePayload(string $method, array $params = []): array {
        return [
            'jsonrpc' => '2.0',
            'id'      => $this->id++,
            'method'  => $method,
            'params'  => $params,
        ];
    }
    
    private function sendRequest($payload) {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $payloadTxt=print_r($payload,true);
            $curlError=curl_error($ch);
            curl_close($ch);
            throw new \Exception("CURL error \non {$this->url}\non $payloadTxt: ".$curlError);
        }
        curl_close($ch);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON response: ' . $raw);
        }
        return $decoded;
    }
    private function handleResponse(array $response, int $expectedId) {
        if (isset($response['error']) && $response['error'] !== null) {
            throw new \Exception('RPC error: ' . json_encode($response['error']));
        }
        if (!isset($response['id']) || $response['id'] !== $expectedId) {
            throw new \Exception("Mismatched response ID (expected $expectedId, got {$response['id']})");
        }
        return $response['result'] ?? null;
    }
}

/* Hash-table functions
    Arrays are not efficient at these large numbers. When they get large they stop performing
    They are replaced by shmop direct memory access; these are accompanied by auxillary table structures
    To mimic named arrays memory is split in a 'hash'-part and a 'bucket'-part.
    The 'hash'-part points to a linked-list of hash-fragment (last four bytes) collisions
    The 'bucket'-part contains individual fixed length records
    store_hash_index():     dump table to disk
    hashtable_initialize(): initialize shmop direct memory block
    hashtable_read():       reads bucket-record into an array
    hashtable_write():      writes array to memory
    hashtable_add_TX():     specific Transaction addition to memory
    hashtable_add_PUB():    specific publicKeyHash addition to memory; This is specific because public-keys can appear multiple times in history. Only the first occurence is added.
    flattable_append():     specific tx-output(txo) addition to memory; no hash meeded; direct access through tx or pubkeyhash
    find():                 return bucket-index and array of records based on ID
    
    auxilary functions:
    base58check_decode():               returns version-byte and pubkeyhash if valid base58 and valid checksum
    pubkeyhash_from_base58_address():   returns pubkeyhash or false
*/
function store_hash_index(&$table){
    file_put_contents(DATA.$table['name']."_aux",json_encode($table));
    $step=10000000; // 10MB ok?
    if ($table['name']!="TXO") {
        for ($i=0;$i<$table['hash'][SIZE];$i+=$step) {
            if ($i==0) {$append=0;} else {$append=FILE_APPEND;}
            if (($i+$step)>$table['hash'][SIZE]) {$size=$table['hash'][SIZE]-$i;} else {$size=$step;}
            file_put_contents(DATA.$table['name']."_hash",shmop_read($table['hash'][P],$i,$size),$append);
        }
    }
    for ($i=0;$i<$table['bucket'][SIZE];$i+=$step) {
        if ($i==0) {$append=0;} else {$append=FILE_APPEND;}
        if (($i+$step)>$table['bucket'][SIZE]) {$size=$table['bucket'][SIZE]-$i;} else {$size=$step;}
        file_put_contents(DATA.$table['name']."_bucket",shmop_read($table['bucket'][P],$i,$size),$append);
    }
}
function dump_index(&$table,$part,$where=""){
    if ($where=="") {$where=DATA.$table['name']."_$part";};
    $step=10000000; // 10MB ok?
    for ($i=0;$i<$table[$part][SIZE];$i+=$step) {
        if ($i==0) {$append=0;} else {$append=FILE_APPEND;}
        if (($i+$step)>$table[$part][SIZE]) {$size=$table[$part][SIZE]-$i;} else {$size=$step;}
        file_put_contents($where,shmop_read($table[$part][P],$i,$size),$append);
    }
}
function load_index(&$table,$part,$where=""){
    if ($where=="") {$where=DATA.$table['name']."_$part";};
    $step=10000000; // 10MB ok?
    $fp = fopen($where, "rb");
    $offset = 0;
    while (!feof($fp)) {
        $chunk = fread($fp, $step);
        shmop_write($table[$part][P],$chunk,$offset);        
        $offset += $step;
    }
    fclose($fp);
}
function hashtable_initialize(&$table){
    $table[P]=@shmop_open($table[KEY],"n",0666, $table[SIZE]);
    if (!$table[P]) {
        $shm=@shmop_open($table[KEY], 'c', 0666, $table[SIZE]);
        if (!$shm) {
                $shm=@shmop_open($table[KEY], 'w', 0666, $table[SIZE]);
        }
        shmop_delete($shm);
        $table[P]=shmop_open($table[KEY],"n",0666, $table[SIZE]);
    }
}
function hashtable_read(&$table,$index){
    if ($table[RECORDSIZE]*$index>$table[SIZE]){
        die("-Reading beyond-{$table[SIZE]}--$index-\n");
    }
    try {
        $data=shmop_read($table[P],$table[RECORDSIZE]*($index-1),$table[RECORDSIZE]);
    } catch (Error $e) {
        L("Error ".$table[NAME]." ".$table[FORMAT_UNPACK]." ".$index." ".$table[RECORDSIZE]."\n");
        die();
    } catch (Exception $e) {
        L("Exception ".$table[NAME]." ".$table[FORMAT_UNPACK]." ".$index." ".$table[RECORDSIZE]."\n");
        die();
    }
    return array_values(unpack($table[FORMAT_UNPACK],$data));
}
function hashtable_write(&$table,$index,$output){
    $data=pack($table[FORMAT_PACK],...$output);
    shmop_write($table[P],$data,$table[RECORDSIZE]*($index-1));
}
function pack_array(string $format, array $args): string {
    return pack($format, ...$args);
}
function hashtable_add_TX(&$table,$record){
/*  An index to search for IDs that arrive as a byte-string with data associated in $record
    Hash is the wrong word but these id's are unique and random as if it were hashes;
    We take the last four bytes (% modulus x) and therefor collisions occur
    These collisions are linked by using the last record-entry (must be provided as zero).
   
    The index consists of two memory-structures:
    - A fixed size (N) hash-index using four-byte pointers. Each position is calculated as hash modulus N
        The pointer points to a linked list of hashes where hash modulus N collides
        As a rule of thumb the size (N) should be chosen to avoid >100 collisions
        So 0M-10M data-records->size==1M; 10M-100M size==10M; >100M size=100M
        Empty records are 0 so apply (value-1) to obtain a pointer
    - A bucket that contains fixed size records:
        - The file-index of the original data (LONG)
        - The last four bytes of the hash to make hash collisions rare
        - A link-pointer to link hashes where (modulus N) collides (0==last item)
    The last link-pointer == 0 so apply (value-1) to obtain the index of the next record
      
    The (hash)index is accompanied by a $table structure to maintain the data:
    $table['hash']          // The (hash)index [memorypointer, size, top]; P=0,SIZE=1,TOP=2
    $table['bucket']        // The bucket [memorypointer, size, top] 
    $table['increment']         // To reduce memory reallocation; Increments size when top reaches size (except for $table['hash'])
    
    verify: SIZE is in bytes, but TOP is an index starting at 1, just like the pointers in the three tables; 
*/
    global $P;
    static $link_max;
    $ID=$record[0];

    // prepare adding new record to bucket-list
    $bucket_index=$table['bucket'][TOP]+1;
    if ($bucket_index*$table['bucket'][RECORDSIZE]>$table['bucket'][SIZE]) { //make room
        dump_index($table,'bucket');
        shmop_delete($table['bucket'][P]);
        $table['bucket'][SIZE]+=$table['bucket'][INCREMENT];
        $table['bucket'][P]=shmop_open($table['bucket'][KEY],"n",0666,$table['bucket'][SIZE]);
        load_index($table,'bucket');
        L("TX-bucket increment\n");
    }
    $table['bucket'][TOP]+=1;

    // Add new record to bucket; the index (pointer/recordsize == TOP) becomes new reference
    //      : tx-fragment, index (in TXdata) and linked-list-end
    // The last four TX-bytes are considered a hash as they are random; these (% 'hash_top') determine the index in the hash-table
    // The first four TX-bytes identify the hash (collisions can still occur)
    hashtable_write($table['bucket'],$bucket_index,$record);
  
    // Calculate start of linked_list;
    // Find end of linked list and point to previous end
    [$fragment]=array_values(unpack("V",substr($ID,-4)));
    $hash_index=1+($fragment % $table['hash'][TOP]);
    [$linked_list]=hashtable_read($table['hash'],$hash_index);
    if ($linked_list==0){ // No linked-list yet; start=0 in bucket; update the hash_table
        hashtable_write($table['hash'],$hash_index,[$bucket_index]);
    } else {
        $i=0;
        do {
            $i++;
            $content=hashtable_read($table['bucket'],$linked_list);
            $next = $content[array_key_last($content)];
            if ($next==0){// At the end; Point to new end
                $content[array_key_last($content)]=$bucket_index;
                hashtable_write($table['bucket'],$linked_list,$content);
            } else {$linked_list=$next;}
        } while ($next!=0);
        if ($i>$link_max) { // Check performance by counting max collisions
            $link_max=$i;
            file_put_contents(DATA."linkmax",$i);
        }
    }
    return $bucket_index;
}
function hashtable_add_PUB(&$table,$record){
     // Will only add a pub-key record if it doest exist yet. Returns a pointer to the bucket-list position (new or old). Also returns a pointer to the last TXO
    static $link_max;
    $ID=$record[0];
    $block=$record[1];
    $last_txo=$record[3]; // carefull with this hard-coding (actually TXO_table[TOP]+1)
    $previous_last_txo=$record[3];

    $bucket_index=$table['bucket'][TOP]+1;
    if ($bucket_index*$table['bucket'][RECORDSIZE]>$table['bucket'][SIZE]) { //make room
        dump_index($table,'bucket');
        shmop_delete($table['bucket'][P]);
        $table['bucket'][SIZE]+=$table['bucket'][INCREMENT];
        $table['bucket'][P]=shmop_open($table['bucket'][KEY],"n",0666,$table['bucket'][SIZE]);
        load_index($table,'bucket');
        L("PUB-bucket increment\n");
    }
    $table['bucket'][TOP]+=1;

    $new=false;
    [$fragment]=array_values(unpack("V",substr($ID,-4)));
    $hash_index=1+($fragment % $table['hash'][TOP]);
    [$linked_list]=hashtable_read($table['hash'],$hash_index);
    if ($linked_list==0){ // No linked-list yet; start=0 in bucket; update the hash_table
        hashtable_write($table['hash'],$hash_index,[$bucket_index]);
    } else {
        $i=0; 
        do {
            $i++;
            $content=hashtable_read($table['bucket'],$linked_list);
            if ($content[0]==$ID) {
                $previous_last_txo=$content[3];
                $content[1]=$block;    // change marker
                $content[3]=$last_txo; // carefull again
                hashtable_write($table['bucket'],$linked_list,$content);
                $table['bucket'][TOP]--;
                return ([$linked_list,$previous_last_txo]); //<--- exit
            } 
            $next = $content[array_key_last($content)];
            if ($next==0){
                $content[array_key_last($content)]=$bucket_index;
                hashtable_write($table['bucket'],$linked_list,$content);
            } else {$linked_list=$next;}
        } while ($next!=0);
        if ($i>$link_max) { 
            $link_max=$i;
            file_put_contents(DATA."link2max",$i);
        }
    }
    hashtable_write($table['bucket'],$bucket_index,$record);
    $new=true;
    return ([$bucket_index,$previous_last_txo]);
}
function flattable_append(&$table,$record){
    $bucket_index=$table['bucket'][TOP]+1;
    if ($bucket_index*$table['bucket'][RECORDSIZE]>$table['bucket'][SIZE]) { //make room
        dump_index($table,'bucket');
        shmop_delete($table['bucket'][P]);
        $table['bucket'][SIZE]+=$table['bucket'][INCREMENT];
        $table['bucket'][P]=shmop_open($table['bucket'][KEY],"n",0666,$table['bucket'][SIZE]);
        load_index($table,'bucket');
        L("TXO-bucket increment\n");
    }
    $table['bucket'][TOP]+=1;
    hashtable_write($table['bucket'],$bucket_index,$record);
    return ($bucket_index);
}
function find($table,$ID){
    [$fragment]=array_values(unpack("V",substr($ID,-4)));
    $hash_index=1+($fragment % $table['hash'][TOP]);
    [$linked_list]=hashtable_read($table['hash'],$hash_index);
    if ($linked_list==0){ // No hash-occurences yet
        return [false,[]];
    } else {
        $i=0;
        do {
            $i++;
            $content=hashtable_read($table['bucket'],$linked_list);
            if ($content[0]==$ID) {
                return([$linked_list,$content]);
            } else {
                $next = $content[array_key_last($content)];
                if ($next==0){
                    return [false,[]];
                } else {
                    $linked_list=$next; 
                }
            }
        } while ($next!=0);
        return [false,[]];
    }
}
function isValidIntString(string $s, int $max): bool {
    // allow only digits and no leading sign; disallow leading zeros (01)
    if (!preg_match('/^[1-9]\d*$/', $s)) return false;
    $val = filter_var($s, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $max]
    ]);
    return $val !== false;
}
?>
