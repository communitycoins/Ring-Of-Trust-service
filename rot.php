<?php

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

$options = getopt('c:h', ['config:', 'help']);
if (isset($options['h']) || isset($options['help'])) {die("Usage: php rot.php --config=/path/to/rot.conf\n");}
$configPath = $options['c'] ?? $options['config'] ?? getenv('ROT_CONFIG') ?? null;
if (!$configPath) {die("Error: --config is required (or set ROT_CONFIG)\n");}
if (!is_file($configPath)) {die("Error: config not found: $configPath\n");}
list($tikker,$user,$ww,$rpcport,$socket,$datadir)=explode("|",trim(file_get_contents($configPath)));

define ("VERSION","0.1");
define("ROOT",dirname($configPath)."/");
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
function now(){return date('d-m-Y H:i');}
function L($what){$extra="";if (substr($what,-1)!="\n"){$extra="\n";}file_put_contents(ROOT."rot.log",$what,FILE_APPEND);echo $what.$extra;}
if (!function_exists('array_key_last')) {function array_key_last(array $array) {if (empty($array)) {return null;}return key(array_slice($array, -1, 1, true));}}

$start=time();
@unlink(DATA."TXidx");
@unlink(DATA."BLKidx");
file_put_contents(ROOT."pid",getmypid());
register_shutdown_function(function(){@unlink(ROOT."pid");});

$rpchost='127.0.0.1';
if (strpos($rpcport,":")>0) {list($rpchost,$rpcport)=explode(":",$rpcport);}
$tikker=strtoupper($tikker);
$versionByte=$versionsBytes[$tikker];
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
    $parseContext=unserialize(file_get_contents(DATA."AUX"));
    $hash = $RPC->call('getblockhash', [$parseContext['height']]);
    if ($hash==$parseContext['hash']) { 
        L("Recovering ...");
        recover(true);
        $height=$parseContext['height']+1; // Next height to look for
        $recovery=true;
    } else {
        L("Failover hash doesn't match core's vision \nRestarting ...\n");
        $recovery=false;
        $parseContext['currentFile']='';
        $parseContext['offset']=0;
        $parseContext['hash']='';
        $parseContext['height']=0;
        // Oeps hoped this wouldn't occur; Start from scratch
    }
}
if (!$recovery){
    $parseContext['currentFile']='';
    $parseContext['offset']=0;
    $TX_table['name']			="TX";
    $TX_table['N']			=10000000;   // hash-positions (4-bytes a piece) -> 40Mb
    $TX_table['increment']              =100000000;  // increment empty space to prevent frequent reallocations (100 Mb)                                        
    if (($tikker=='LTC')||($tikker=='BTC')||($tikker=='DOGE')) {$multiplier=10;} else {$multiplier=1;}   // hash-positions -> 400Mb
    $TX_table['N']*=$multiplier;
    $TX_table['increment']*=$multiplier;
    
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
    $TX_table['bucket'][SIZE]=$TX_table['bucket'][INCREMENT];
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
    $PUB_table['bucket'][SIZE]=$PUB_table['bucket'][INCREMENT];
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
    $TXO_table['bucket'][SIZE]=$TXO_table['bucket'][INCREMENT];
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
            backup(true);
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
            $lastBlockHash = $RPC->call('getblockhash', [$height]);
            $height++;
            continue;
        } elseif (file_exists(ROOT."recover")) {
            L("recovery test: REWIND at $height\n");
            recover();
            $height=$parseContext['height'];
            $lastBlockHash = $RPC->call('getblockhash', [$height]);
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
            $parseContext['hash']=$entry['prevHash'];
            $parseContext['height']=$height-1; // -1 because arriving entry isn't indexed yet
            backup(); // No need to toutch backupHeight
        }
        $parsed = $parser->getBlock($entry['raw']);
        $skipped+=$parsed['skipped'];
        $relevant+=count($parsed['transactions']);
        if (!$fullBackup){
            $TXidx.=pack('Vv',$TX_sum,count($parsed['transactions']));  // All relevant tx's per block (can be 0)
            $BLKidx.=pack('vV',$entry['fileNumber'],$entry['offset']);  // pointer to block in blk*.dat (to avoid rpc getrawtransaction/txindex=1)
        }
        foreach ($parsed['transactions'] as $tx){
            $txID=hashtable_add_TX($TX_table,[hex2bin($tx['txid']),$height,$TXO_table['bucket'][TOP]+1,0]);
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
                try {
                    $blockHash     = $RPC->call('getblockhash',[$height]);
                    $rawHex        = $RPC->call('getblock',[$blockHash, false]);
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
                            $top=$RPC->call('getblockcount',[]);
                            if ($height-1==$top) {$tipReached=true;} // height is the next block we are waiting for
                        }
                    }
                } catch (\GuzzleHttp\Exception\ConnectException $e) {
                    if (!$coreFailure) {
                        $coreFailure=true;
                        L("Cannot reach core: ".now()." ". $e->getMessage());
                    }
                    sleep(10);
                    break;
                } catch (Exception $e) { // Not available yet
                    $coreFailure=false;
                    if (strpos($e->getMessage(),'"code":-8')===false) {
                    if (strpos($e->getMessage(),'"code":-1')===false) {
                        L('Caught exception: '.$e->getMessage()."\n");
                    }}
                    sleep(1);
                    break;
                }
                $coreFailure=false;                
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
function backup($full=false){
    /* Backup occurs at $parseContext['height']. The block at that height was fully indexed
       When you recover, start looking for the next height
    */
    global $TX_table,$PUB_table,$TXO_table,$parseContext;

    $time=microtime(true);
    if (!$full) {
        if (file_exists(DATA."_backup_1")) {$delta_1=time()-filemtime(DATA."_backup_1");} else {$delta_1=time();}
        if (file_exists(DATA."_backup_2")) {$delta_2=time()-filemtime(DATA."_backup_2");} else {$delta_2=time();}
        if ($delta_1>$delta_2) {$postfix="_backup_1";} else {$postfix="_backup_2";} // replace oldest
        L("Backup $postfix at height {$parseContext['height']}: ");
        
        file_put_contents(DATA."TX_aux$postfix",serialize(stripResources($TX_table)));
        file_put_contents(DATA."PUB_aux$postfix",serialize(stripResources($PUB_table)));
        file_put_contents(DATA."TXO_aux$postfix",serialize(stripResources($TXO_table)));
        file_put_contents(DATA."$postfix",serialize($parseContext));
        
        dump_index($TX_table,"hash",DATA."TX_hash$postfix");
        dump_index($PUB_table,"hash",DATA."PUB_hash$postfix");
    } else {
        L("Backup Full at height {$parseContext['height']}: ");
        file_put_contents(DATA."AUX",serialize($parseContext));
        file_put_contents(DATA."TX_aux",serialize(stripResources($TX_table)));
        file_put_contents(DATA."PUB_aux",serialize(stripResources($PUB_table)));
        file_put_contents(DATA."TXO_aux",serialize(stripResources($TXO_table)));
        dump_index($TX_table,"hash",DATA."TX_hash");
        dump_index($PUB_table,"hash",DATA."PUB_hash");
        dump_index($TX_table,"bucket",DATA."TX_bucket");
        dump_index($PUB_table,"bucket",DATA."PUB_bucket");
        dump_index($TXO_table,"bucket",DATA."TXO_bucket");
    }
    L((microtime(true)-$time)."(s)\n");
}
function recover($full=false) { // Rewinds to a valid backup-tip
    global $TX_table,$PUB_table,$TXO_table,$parseContext,$RPC;  
    $time=microtime(true);

    if ($full) {
        $parseContext=unserialize(file_get_contents(DATA."AUX"));
        L("Recover Full till height ".$parseContext['height']."\n");
        $TX_table=unserialize(file_get_contents(DATA."TX_aux"));
        $PUB_table=unserialize(file_get_contents(DATA."PUB_aux"));
        $TXO_table=unserialize(file_get_contents(DATA."TXO_aux"));
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
    } else {
        if (file_exists(DATA."_backup_1")) {$delta_1=time()-filemtime(DATA."_backup_1");} else {$delta_1=time();}
        if (file_exists(DATA."_backup_2")) {$delta_2=time()-filemtime(DATA."_backup_2");} else {$delta_2=time();}
        if ($delta_1>$delta_2) {$postfix="_backup_2";} else {$postfix="_backup_1";} // try youngest first
        L("Recover $postfix at height {$parseContext['height']}\n");
    
        $parseContext=unserialize(file_get_contents(DATA.$postfix));
        $hash = $RPC->call('getblockhash', [$parseContext['height']]);
        if ($hash!=$parseContext['hash']) { // rewind deeper
            if ($postfix=="_backup_1") {$postfix="_backup_2";} else {$postfix="_backup_1";}
            $parseContext=json_decode(file_get_contents(DATA.$postfix),true);
            $hash = $RPC->call('getblockhash', [$parseContext['height']]);
            if ($hash!=$parseContext['hash']) { // Oeps hoped this wouldn't occur; Both backups dont conform. Try latest Full backup
                $parseContext=unserialize(file_get_contents(DATA."AUX"));
                if ($hash!=$parseContext['hash']) {                    
                    die("Cannot recover from backup at height {$parseContext['height']}\n Try full recovery (remove contents of data-directory).");
                } else {
                    recover(true);
                    return;
                }
            }
        }

        $TX_table_backup=unserialize(file_get_contents(DATA."TX_aux".$postfix));
        $PUB_table_backup=unserialize(file_get_contents(DATA."PUB_aux".$postfix));
        $TXO_table_backup=unserialize(file_get_contents(DATA."TXO_aux".$postfix));

        hashtable_initialize($TX_table['hash']);
        hashtable_initialize($PUB_table['hash']);
        load_index($TX_table,'hash',DATA."TX_hash$postfix");
        load_index($PUB_table,'hash',DATA."PUB_hash$postfix");
        
        //PUB_table-bucket(36): [0]scripthash(20) + [1]blocknr/lastchange(4) + [2]first txo(4) + [3]last txo(4) + [4]collision-linked-list(4)
        //TXO_table_bucket(28): [0]txin(4) + [1]nout(4) + [2]value(8) + [3]scripthash(4) + [4]txout/spend(4) + [5]scripthash-txo-linkedlist(4) 
        $truncTXOEnd  =$TXO_table['bucket'][TOP];
        $truncTXOStart=$TXO_table_backup['bucket'][TOP]+1;
        $truncPUBstart=$PUB_table_backup['bucket'][TOP]+1;
        if (DEBUG) {L("$truncTXOStart,$truncTXOEnd,$truncPUBstart\n");}
        $affected=[];
        for ($i=$truncTXOStart;$i<=$truncTXOEnd;$i++) {
            $content=hashtable_read($TXO_table['bucket'],$i);
            if ($content[3]<$truncPUBstart) {$affected[$content[3]]=true;}
        }
        L((1+$truncTXOEnd-$truncTXOStart)." txo's concerned; ".count($affected)." pubkeys affected.\n");
        foreach ($affected as $PUB_index=>$dummy){
            $PUB_content=hashtable_read($PUB_table['bucket'],$PUB_index);
            $more=true;
            $TXO_index=$PUB_content[2];  // first
            while ($more) {
                $TXO_content=hashtable_read($TXO_table['bucket'],$TXO_index);
                if ($TXO_content[5]==0) {
                    L('Broken TXO-linked list');
                    die();
                } elseif ($TXO_content[5]>=$truncTXOStart) {
                    $more=false;
                    $PUB_content[3]=$TXO_index;
                    hashtable_write($PUB_table['bucket'],$PUB_index,$PUB_content);
                    $TXO_content[5]=0;
                    hashtable_write($TXO_table['bucket'],$TXO_index,$TXO_content);
                } else {
                    $TXO_index=$TXO_content[5];  // next
                }
            }            
        }
        $TX_table['bucket'][TOP]=$TX_table_backup['bucket'][TOP];
        $PUB_table['bucket'][TOP]=$PUB_table_backup['bucket'][TOP];
        $TXO_table['bucket'][TOP]=$TXO_table_backup['bucket'][TOP];
        // trunc TX_table; ($i=1 Can be optimized by finding the first transaction at blockheight==backup[TOP])
        $collisionPointer=3;
        $top=$TX_table['bucket'][TOP];
        for ($i=1;$i<=$top;$i++) {
            $content=hashtable_read($TX_table['bucket'],$i);
            if ($content[$collisionPointer]>$top) {
               $content[$collisionPointer]=0;
               hashtable_write($TX_table['bucket'],$i,$content);
            } 
        }
        // trunc PUB_table
        $collisionPointer=4;
        $top=$PUB_table['bucket'][TOP];
        for ($i=1;$i<=$top;$i++) {
            $content=hashtable_read($PUB_table['bucket'],$i);
            if ($content[$collisionPointer]>$top) {
               $content[$collisionPointer]=0;
               hashtable_write($PUB_table['bucket'],$i,$content);
            } 
        }
    }
}
function handleSocketRequests(float $deadline){
    global $alphabet;
    static $clients = [];
    static $server;
    static $rot=[];
    static $network=[];
    
    if ($network === null) {
    }
    
    if ($server === null) {
        if (!file_exists(DATA.'rot')) {
            $rot['auth']="";
            for ($i=0;$i<32;$i++) {$rot['auth'].=$alphabet[mt_rand(0,57)];}
            //$rot['candidates']=parse_peers_dat();
            //$rot['candidateBatch']=time();
        }
        $server = stream_socket_server("tcp://0.0.0.0:".SOCKET, $errno, $errstr);
        if (!$server) {die("Socket error: $errstr ($errno)");}
        stream_set_blocking($server, false);
    }
    $write  = null;
    $except = null;
    $timeout_sec=0;
    while (microtime(true) < $deadline) {
        $read = $clients;
        $read[] = $server;

        $remainingTime = $deadline - microtime(true);
        if ($remainingTime <= 0) break;

        $timeout_usec = (int) floor(($remainingTime - $timeout_sec) * 1000000);
        if ($timeout_usec > 999999) { $timeout_sec += 1; $timeout_usec = 0; } // guard
        
        $ready = @stream_select($read, $write, $except, $timeout_sec, $timeout_usec); 
        if ($ready === false) {
            $err = error_get_last();
            if (strpos($err['message'] ?? '', 'Interrupted system call') !== false) {
                continue; // harmless
            }
            L("stream_select failed: " . $err['message']);
            break;
        }

        foreach ($read as $sock) {
            if ($sock === $server) {
                $client = stream_socket_accept($server, 0);
                if ($client) {
                    stream_set_blocking($client, false);
                    $clients[] = $client;
                }
            } else {
                $line = stream_get_line($sock, 65536, "\n");
                $meta = stream_get_meta_data($sock);
                if ($meta['timed_out'] || $line === false || feof($sock)) {
                    fclose($sock);
                    if (DEBUG) {echo "socket issue...\n";}
                    $clients = array_filter($clients, fn($c) => $c !== $sock);
                } else {
                    if (DEBUG) {echo "$line\n";}
                    $response = handleClientRequest($line);
                    fwrite($sock, $response . "\n");
                    fflush($sock);
                    fclose($sock);
                    if (DEBUG) {echo "$response\n";}
                    $clients = array_filter($clients, fn($c) => $c !== $sock);
                }
            }
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

function handleClientRequest($request) {
    global $height,$TX_table,$TXO_table,$PUB_table,$versionByte;
    
    $start=microtime(true);
    $cmd=explode("|",trim($request));
    if (count($cmd)!=3) {  // ID|CMD|params
        return "3!\n";
    }
    $a=$cmd[1];$b=$cmd[2];
    $output="";
    if ($a=="blk") {        
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
    public $tip;
    public $maxReorgDepth=100;
    public $backupHeight;
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
        $once=true;
        while (true) {
            $tipHash   = $RPC->call('getbestblockhash', []);
            $tip = $RPC->call('getblock', [$tipHash]);
            $lag       = time() - $tip['time'];
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

    public function __construct(array $rpc) {
        $this->url = "http://{$rpc['user']}:{$rpc['pass']}@{$rpc['host']}:{$rpc['port']}/";
    }
    public function call( $method, $params = []) {
        $payload = $this->makePayload($method, $params);
        $response = $this->sendRequest($payload);
        return $this->handleResponse($response, $payload['id']);
    }
    public function batch(array $calls): array {
        $batch = [];
        $ids = [];
        foreach ($calls as [$method, $params]) {
            $payload = $this->makePayload($method, $params);
            $batch[] = $payload;
            $ids[$payload['id']] = $method;
        }
        $responses = $this->sendRequest($batch);
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
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $payloadTxt=print_r($payload,true);
            throw new \Exception("CURL error \non {$this->url}\non $payloadTxt: " . curl_error($ch));
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
    $table['hash']   		// The (hash)index [memorypointer, size, top]; P=0,SIZE=1,TOP=2
    $table['bucket'] 		// The bucket [memorypointer, size, top] 
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