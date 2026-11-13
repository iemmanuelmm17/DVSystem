<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/app.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$c = ['WEBRESPONDER_URL'=>'https://example.test/index.php','WEBRESPONDER_KEY'=>'a&b','CALLER_ID_DIGIT_STYLE'=>'words'];
check(spokenPhone('845-263-2561', 'words') === 'eight, four, five, two, six, three, two, five, six, one', 'word digits');
check(spokenPhone('845', 'hyphens') === '8-4-5', 'hyphen digits');
check(str_contains(gather($c, 'abc', 'Use <one>'), 'Use &lt;one&gt;'), 'XML escaping');
check(str_contains(gather($c, 'abc', 'Hello'), 'key=a%26b&amp;state=abc'), 'callback encoding');
check(sipDestination('1234', '9720.DynaCloud') === 'sip:1234@9720.DynaCloud', 'SIP destination');
check(sipDestination('1234@example.org', '9720.DynaCloud') === null, 'reject injected destination');
check(sipDestination('1234', 'bad/domain') === null, 'reject invalid domain');
$s=['office_destination'=>'sip:1234@9720.DynaCloud'];
check(str_contains(endCall($c,$s,'Transfer.',true), '<Forward>sip:1234@9720.DynaCloud</Forward>'), 'office forward');
$s=[]; $xml=mainMenu($c,'abc',$s);
check($s['step'] === 'menu' && $s['digits'] === 1 && str_contains($s['prompt'], 'subscriptions'), 'menu state');
check(str_contains($xml, '<Gather'), 'menu XML');
$s=['step'=>'menu','attempts'=>0,'prompt'=>'Select.'];
$c['MAX_ATTEMPTS']='1';
check(str_contains(processCall($c,'abc',$s,[]), '<Hangup/>') && $s['ended'], 'timeout end');
echo "Smoke tests passed\n";
