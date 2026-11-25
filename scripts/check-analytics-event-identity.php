<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../server-php/src/EstateAnalytics/AicountlyAnalytics.php';
use Aicountly\Analytics\Analytics;
$dir = sys_get_temp_dir() . '/analytics-identity-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$batches = [];
$client = new Analytics(['enabled'=>'1', 'source'=>'books', 'environment'=>'production',
    'url'=>'https://analytics.example.test/api', 'key'=>'test-only', 'spool_dir'=>$dir, 'flush'=>'cron'],
    static function ($method, $url, $headers, $body, $timeout) use (&$batches): array {
        $events = json_decode($body, true)['events'];
        $batches[] = $events;
        if (count($batches) === 1) return [503, '{}'];
        return [200, json_encode(['data'=>['results'=>array_map(static fn($i)=>['index'=>$i,'status'=>'accepted'], array_keys($events))]])];
    });
try {
    $ids=[];
    for ($i=0; $i<20; $i++) $ids[]=$client->track('page.viewed', ['page_path'=>'/page'.$i]);
    if (in_array(null,$ids,true) || count(array_unique($ids))!==20) throw new RuntimeException('Distinct same-second events collided');
    $fixed=$client->track('invoice.created',['idempotency_key'=>'invoice:stable:1']);
    if ($fixed!==Analytics::eventIdFor('books','invoice:stable:1')) throw new RuntimeException('Explicit business key changed');
    $client->flush();
    foreach (glob($dir.'/*.json') as $file) {
        $event=json_decode(file_get_contents($file),true);
        $event['_next_attempt_at']=0;
        file_put_contents($file,json_encode($event));
    }
    $result=$client->flush();
    if(count($batches)!==2 || $result['accepted']!==21) throw new RuntimeException('Retry did not drain the batch');
    foreach($batches as &$batch)foreach($batch as &$event)unset($event['attempt']);
    unset($batch,$event);
    if($batches[0]!==$batches[1])throw new RuntimeException('Retry changed event identity or payload');
    echo "PASS: distinct events, explicit keys and stable retry payloads\n";
} finally {
    foreach(scandir($dir) as $name)if($name!=='.' && $name!=='..' && is_file($dir.'/'.$name))unlink($dir.'/'.$name);
    rmdir($dir);
}
