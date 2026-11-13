<?php
declare(strict_types=1);

function config(): array {
    $path = (getenv('DVAPP_PRIVATE_DIR') ?: 'D:/private/dvapp') . '/.env';
    $c = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $c[trim($key)] = trim(trim($value), "\"'");
        }
    }
    foreach (['DV_API_BASE_URL','DV_API_KEY','ENABLE_RENEWALS','WEBRESPONDER_KEY','WEBRESPONDER_URL','OFFICE_DESTINATION','STATE_DIR','LOG_DIR','CALLER_ID_DIGIT_STYLE','TTS_VOICE','TTS_LANGUAGE','INPUT_TIMEOUT_SECONDS','INTERDIGIT_TIMEOUT_SECONDS','MAX_ATTEMPTS','LOG_LEVEL','LOG_RETENTION_DAYS'] as $key) {
        $value = getenv($key); if ($value !== false) $c[$key] = $value;
    }
    return $c + ['DV_API_BASE_URL'=>'https://dev.deevoch.com/api/phone/v1','CALLER_ID_DIGIT_STYLE'=>'words','INPUT_TIMEOUT_SECONDS'=>'6','INTERDIGIT_TIMEOUT_SECONDS'=>'5','MAX_ATTEMPTS'=>'3','LOG_LEVEL'=>'info','LOG_RETENTION_DAYS'=>'30'];
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function bounded(array $c, string $k, int $default, int $min, int $max): int { $v = filter_var($c[$k] ?? $default, FILTER_VALIDATE_INT); return $v === false ? $default : max($min, min($max, $v)); }
function logEvent(array $c, string $level, string $message): void {
    $levels = ['error'=>0,'warning'=>1,'info'=>2,'debug'=>3];
    if (($levels[$level] ?? 0) > ($levels[$c['LOG_LEVEL'] ?? 'info'] ?? 2) || empty($c['LOG_DIR'])) return;
    $dir = $c['LOG_DIR']; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    // Messages are fixed strings only, never request payloads, tokens or API responses.
    @file_put_contents($dir.'/'.gmdate('Y-m-d').'.log', gmdate('c').' '.$level.' '.$message."\n", FILE_APPEND | LOCK_EX);
    $days = bounded($c, 'LOG_RETENTION_DAYS', 30, 0, 36500);
    if ($days > 0) foreach (glob($dir.'/*.log') ?: [] as $file) if (preg_match('/^\d{4}-\d{2}-\d{2}\.log$/', basename($file)) && filemtime($file) < time()-$days*86400) @unlink($file);
}
function api(array $c, string $method, string $path, ?array $body = null, ?string $session = null, ?string $idempotencyKey = null): array {
    $ch = curl_init(rtrim($c['DV_API_BASE_URL'], '/').$path);
    $headers = ['Authorization: Bearer '.$c['DV_API_KEY'], 'Accept: application/json', 'Content-Type: application/json'];
    if ($session !== null) $headers[] = 'X-Session-Token: '.$session;
    if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: '.$idempotencyKey;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return ['status'=>0, 'error'=>['code'=>'server_error']];
    return ['status'=>$status] + $decoded;
}
function spokenPhone(string $phone, string $style): string {
    $digits = str_split(preg_replace('/\D/', '', $phone));
    if ($style === 'words') { $words = ['zero','one','two','three','four','five','six','seven','eight','nine']; return implode(', ', array_map(fn($d) => $words[(int)$d], $digits)); }
    return implode(match($style) { 'hyphens'=>'-', 'spaces'=>' ', default=>', ' }, $digits);
}
function say(array $c, string $text): string {
    $attrs = ''; foreach (['voice'=>'TTS_VOICE','language'=>'TTS_LANGUAGE'] as $attr=>$key) if (!empty($c[$key])) $attrs .= ' '.$attr.'="'.esc($c[$key]).'"';
    return '<Say'.$attrs.'>'.esc($text).'</Say>';
}
function callback(array $c, string $id): string { return $c['WEBRESPONDER_URL'].'?'.http_build_query(['key'=>$c['WEBRESPONDER_KEY'],'state'=>$id], '', '&', PHP_QUERY_RFC3986); }
function gather(array $c, string $id, string $text, int $digits = 1): string {
    return '<Gather input="dtmf" numDigits="'.$digits.'" timeout="'.bounded($c,'INPUT_TIMEOUT_SECONDS',6,1,60).'" digitEndTimeout="'.bounded($c,'INTERDIGIT_TIMEOUT_SECONDS',5,1,30).'" action="'.esc(callback($c,$id)).'">'.say($c,$text).'</Gather>';
}
function outputXml(string $body): void { header('Content-Type: application/xml; charset=utf-8'); header('Cache-Control: no-store'); echo '<?xml version="1.0" encoding="UTF-8"?><Response>'.$body.'</Response>'; }
function sipDestination(string $destination, string $domain): ?string {
    // Restrict both parts to dial-plan-safe identifiers; never accept a full SIP URI.
    if (!preg_match('/^[A-Za-z0-9_+*#-]{1,64}$/D', $destination)) return null;
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $domain) || str_contains($domain, '..')) return null;
    return 'sip:'.$destination.'@'.$domain;
}
function endCall(array $c, array &$s, string $message, bool $transfer = false): string {
    if (!empty($s['session'])) { api($c, 'DELETE', '/session', null, $s['session']); unset($s['session']); }
    $s['ended'] = true;
    $destination = $s['office_destination'] ?? ($c['OFFICE_DESTINATION'] ?? '');
    if ($transfer && $destination !== '') return say($c,$message).' <Forward>'.esc($destination).'</Forward>';
    return say($c, $message.($transfer ? ' Please contact the office for assistance.' : '')).'<Hangup/>';
}
function failApi(array $c, array &$s, array $result): string {
    $code = $result['error']['code'] ?? 'server_error';
    logEvent($c,'warning','API request failed: '.preg_replace('/[^a-z_]/', '', (string)$code));
    if ($code === 'session_required') return endCall($c,$s,'Your verification session has expired. Please call again to verify.');
    return endCall($c,$s,'Sorry, we cannot complete that request right now.',true);
}
function mainMenu(array $c, string $id, array &$s, string $prefix = ''): string {
    $s['step']='menu'; $s['attempts']=0; $s['digits']=1;
    $s['prompt']=$prefix.' Press 1 to hear your subscriptions. Press 2 for the office. Press 3 to review or delete saved cards. Press 4 to hear your account details. Press 9 to end the call.';
    return gather($c,$id,$s['prompt']);
}
function cardMenu(array $c, string $id, array &$s, string $purpose): string {
    $r=api($c,'GET','/customers/'.(int)$s['customer'].'/cards',null,$s['session']);
    if ($r['status'] !== 200) return failApi($c,$s,$r);
    $cards=$r['data'] ?? [];
    if (!$cards) return mainMenu($c,$id,$s,'You have no saved cards. To add one, contact the office.');
    if (count($cards)>8) return endCall($c,$s,'There are too many cards to list. Please hold for the office.',true);
    $s['cards']=$cards; $s['card_purpose']=$purpose; $s['step']='select_card'; $s['attempts']=0;
    $parts=[];
    foreach ($cards as $i=>$card) $parts[]='Press '.($i+1).' for '.($card['type'] ?? 'card').' ending in '.spokenPhone((string)($card['last_four'] ?? ''),'words').', expires '.($card['expires'] ?? 'unknown').(!empty($card['expired'])?', expired':'').'.';
    $s['prompt']=implode(' ', $parts).' Press 9 to end the call.';
    return gather($c,$id,$s['prompt']);
}
function processCall(array $c, string $id, array &$s, array $request, ?callable $checkpoint = null): string {
    $digit = (string)($request['Digits'] ?? '');
    $step = $s['step'] ?? 'start';
    if (!empty($s['ended'])) return '<Hangup/>';
    if ($step !== 'start' && $digit === '') {
        $s['attempts'] = ($s['attempts'] ?? 0) + 1;
        if ($s['attempts'] >= bounded($c,'MAX_ATTEMPTS',3,1,10)) return endCall($c,$s,'We did not receive a response. Goodbye.');
        return gather($c,$id,$s['prompt'] ?? 'Please enter your selection.', (int)($s['digits'] ?? 1));
    }
    if ($step !== 'start' && $digit === '9' && !in_array($step,['code','phone'],true)) return endCall($c,$s,'Goodbye.');
    switch ($step) {
        case 'start':
            if (isset($request['destination'])) {
                $destination = sipDestination((string)$request['destination'], (string)($request['AccountDomain'] ?? ''));
                if ($destination === null) return endCall($c,$s,'The office transfer destination is not configured correctly.');
                $s['office_destination'] = $destination;
            }
            $phone = preg_replace('/\D/', '', (string)($request['NmsAni'] ?? ''));
            if (strlen($phone) < 10 || strlen($phone) > 11) return endCall($c,$s,'We could not identify your phone number.',true);
            $s['phone']=$phone;
            $r=api($c,'POST','/callers/lookup',['phone'=>$phone]);
            if ($r['status'] !== 200) return failApi($c,$s,$r);
            if (empty($r['data']['is_customer'])) return endCall($c,$s,'For a new subscription, please speak with our office.',true);
            $s['step']='confirm_phone';
            $s['prompt']='We found an account for '.spokenPhone($phone,$c['CALLER_ID_DIGIT_STYLE']).'. Press 1 to receive a verification code by text. Press 2 for the office.';
            return gather($c,$id,$s['prompt']);
        case 'confirm_phone':
            if ($digit === '2') return endCall($c,$s,'Please hold for the office.',true);
            if ($digit !== '1') break;
            $r=api($c,'POST','/verifications',['type'=>'phone','value'=>$s['phone']]);
            if ($r['status'] !== 200 && $r['status'] !== 201) return failApi($c,$s,$r);
            $s['verification']=$r['data']['verification_id']; $s['step']='code'; $s['attempts']=0; $s['digits']=6;
            $s['prompt']='Enter the six digit verification code sent to your phone.';
            return gather($c,$id,$s['prompt'],6);
        case 'code':
            if (!preg_match('/^\d{6}$/', $digit)) break;
            $r=api($c,'POST','/verifications/'.rawurlencode($s['verification']).'/check',['code'=>$digit]);
            if (($r['error']['code'] ?? '') === 'wrong_code') {
                $s['attempts']=($s['attempts']??0)+1;
                if ($s['attempts'] >= bounded($c,'MAX_ATTEMPTS',3,1,10)) return endCall($c,$s,'Too many attempts. Please call again.');
                return gather($c,$id,'That code is not correct. '.$s['prompt'],6);
            }
            if ($r['status'] !== 200) return failApi($c,$s,$r);
            $s['session']=$r['data']['session_token']; $s['customers']=$r['data']['customers'] ?? []; unset($s['verification']);
            if (count($s['customers']) === 1) { $s['customer']=$s['customers'][0]['id']; return mainMenu($c,$id,$s); }
            if (count($s['customers']) === 0 || count($s['customers']) > 8) return endCall($c,$s,'Please hold for help selecting your account.',true);
            $parts=[]; foreach ($s['customers'] as $i=>$customer) $parts[]='Press '.($i+1).' for '.$customer['name'].'.';
            $s['step']='customer'; $s['digits']=1; $s['attempts']=0; $s['prompt']=implode(' ', $parts).' Press 9 to end the call.';
            return gather($c,$id,$s['prompt']);
        case 'customer':
            $i=(int)$digit-1; if (!ctype_digit($digit) || !isset($s['customers'][$i])) break;
            $s['customer']=$s['customers'][$i]['id']; return mainMenu($c,$id,$s);
        case 'menu':
            if ($digit === '2') return endCall($c,$s,'Please hold for the office.',true);
            if ($digit === '3') return cardMenu($c,$id,$s,'delete');
            if ($digit === '4') {
                $r=api($c,'GET','/customers/'.(int)$s['customer'],null,$s['session']);
                if ($r['status'] !== 200) return failApi($c,$s,$r);
                $profile=$r['data'];
                return mainMenu($c,$id,$s,'The account is under '.($profile['name'] ?? 'unknown').'. The telephone number is '.spokenPhone((string)($profile['phone'] ?? ''),$c['CALLER_ID_DIGIT_STYLE']).'. For changes or other details, please contact the office.');
            }
            if ($digit !== '1') break;
            $r=api($c,'GET','/customers/'.(int)$s['customer'].'/subscriptions',null,$s['session']);
            if ($r['status'] !== 200) return failApi($c,$s,$r);
            $s['subscriptions']=$r['data'] ?? [];
            if (!$s['subscriptions']) return mainMenu($c,$id,$s,'There are no subscriptions on this account.');
            if (count($s['subscriptions']) > 8) return endCall($c,$s,'There are too many subscriptions to list here. Please hold for the office.',true);
            $parts=[]; foreach ($s['subscriptions'] as $i=>$sub) $parts[]='Press '.($i+1).' for '.($sub['plan']['name'] ?? 'subscription').', '.($sub['status'] ?? '').', delivery to '.($sub['delivery']['address_line_1'] ?? 'address unavailable').'.';
            $s['step']='subscription'; $s['prompt']=implode(' ', $parts).' Press 9 to end the call.'; $s['attempts']=0;
            return gather($c,$id,$s['prompt']);
        case 'subscription':
            $i=(int)$digit-1; if (!ctype_digit($digit) || !isset($s['subscriptions'][$i])) break;
            $sub=$s['subscriptions'][$i]; $s['subscription']=$sub['id'];
            $s['step']='subscription_menu'; $s['attempts']=0;
            $s['prompt']='This subscription is '.($sub['status'] ?? 'unknown').'. It ends '.($sub['end'] ?? 'on an unavailable date').'. Auto renew is '.(!empty($sub['auto_renew'])?'on':'off').'. Press 1 to change auto renew. Press 2 for the office. Press 3 for the main menu. Press 4 to get a renewal quote. Press 5 to change the saved card on this subscription. Press 9 to end the call.';
            return gather($c,$id,$s['prompt']);
        case 'subscription_menu':
            if ($digit === '5') return cardMenu($c,$id,$s,'subscription');
            if ($digit === '2') return endCall($c,$s,'Please hold for the office.',true);
            if ($digit === '3') return mainMenu($c,$id,$s);
            if ($digit === '4') {
                if (strtolower($c['ENABLE_RENEWALS'] ?? 'false') !== 'true') return endCall($c,$s,'Renewals are not available through this system. Please hold for the office.',true);
                $r=api($c,'GET','/customers/'.(int)$s['customer'].'/subscriptions/'.(int)$s['subscription'].'/renewal',null,$s['session']);
                if ($r['status'] !== 200) return failApi($c,$s,$r);
                $quote=$r['data'] ?? [];
                if (!isset($quote['price'], $quote['currency'], $quote['start'], $quote['end'], $quote['card']['last_four']) || !is_numeric($quote['price'])) return endCall($c,$s,'The renewal quote is incomplete.',true);
                $s['renewal_quote']=$quote; $s['step']='renew_auto'; $s['attempts']=0;
                $warnings=implode(' ',array_filter($quote['warnings'] ?? [],'is_string'));
                $s['prompt']='Renewal costs '.$quote['price'].' '.$quote['currency'].', from '.$quote['start'].' until '.$quote['end'].'. '.$warnings.' Press 1 to renew with auto renew on. Press 2 to renew with auto renew off. Press 3 to cancel.';
                return gather($c,$id,$s['prompt']);
            }
            if ($digit !== '1') break;
            $s['step']='auto_renew'; $s['attempts']=0; $s['prompt']='Press 1 to turn auto renew on. Press 2 to turn it off. Press 3 to go back. Press 9 to end the call.';
            return gather($c,$id,$s['prompt']);
        case 'select_card':
            $i=(int)$digit-1;
            if (!ctype_digit($digit) || !isset($s['cards'][$i])) break;
            $s['selected_card']=$s['cards'][$i];
            $s['step']='confirm_card'; $s['attempts']=0;
            $desc=($s['selected_card']['type'] ?? 'card').' ending in '.spokenPhone((string)($s['selected_card']['last_four'] ?? ''),'words');
            $s['prompt']=($s['card_purpose'] === 'delete' ? 'To delete the saved '.$desc : 'To use '.$desc.' for this subscription').', press 1. To cancel, press 2.';
            return gather($c,$id,$s['prompt']);
        case 'confirm_card':
            if ($digit === '2') return mainMenu($c,$id,$s,'No change was made.');
            if ($digit !== '1') break;
            if ($s['card_purpose'] === 'delete') {
                $r=api($c,'DELETE','/customers/'.(int)$s['customer'].'/cards/'.(int)$s['selected_card']['id'],null,$s['session']);
                if (($r['error']['code'] ?? '') === 'card_in_use') return mainMenu($c,$id,$s,'That card is still used by a subscription and cannot be deleted.');
                if ($r['status'] !== 200 || empty($r['data']['deleted'])) return failApi($c,$s,$r);
                return mainMenu($c,$id,$s,'The card was deleted.');
            }
            $r=api($c,'PUT','/customers/'.(int)$s['customer'].'/subscriptions/'.(int)$s['subscription'].'/card',['card_id'=>$s['selected_card']['id']],$s['session']);
            if ($r['status'] !== 200) return failApi($c,$s,$r);
            return mainMenu($c,$id,$s,'The subscription card was changed.');
        case 'renew_auto':
            if ($digit === '3') return mainMenu($c,$id,$s,'No charge was made.');
            if ($digit !== '1' && $digit !== '2') break;
            $s['renew_auto']=$digit === '1'; $s['step']='renew_confirm'; $s['attempts']=0;
            $q=$s['renewal_quote']; $card=$q['card'] ?? [];
            $s['prompt']='Confirm a charge of '.$q['price'].' '.$q['currency'].' to the card ending in '.spokenPhone((string)($card['last_four'] ?? ''),'words').', for '.$q['start'].' through '.$q['end'].', with auto renew '.($s['renew_auto']?'on':'off').'. Press 1 to authorize the charge. Press 2 to cancel.';
            return gather($c,$id,$s['prompt']);
        case 'renew_confirm':
            if ($digit === '2') return mainMenu($c,$id,$s,'No charge was made.');
            if ($digit !== '1') break;
            // Persist the attempt before contacting the API. A crash or ambiguous response
            // must not invite a second charge under a fresh key.
            if ($checkpoint === null) return endCall($c,$s,'The renewal cannot be processed safely.',true);
            $s['renewal_key']=bin2hex(random_bytes(16)); $s['step']='renew_uncertain';
            $checkpoint($s);
            $r=api($c,'POST','/customers/'.(int)$s['customer'].'/subscriptions/'.(int)$s['subscription'].'/renew', ['expected_amount'=>$s['renewal_quote']['price'],'auto_renew'=>$s['renew_auto']],$s['session'],$s['renewal_key']);
            if ($r['status'] === 200 || $r['status'] === 201) {
                $end=$r['data']['subscription']['end'] ?? 'unavailable';
                unset($s['renewal_key'], $s['renewal_quote']);
                return mainMenu($c,$id,$s,'Your renewal was completed. The subscription ends '.$end.'.');
            }
            $code=$r['error']['code'] ?? '';
            if ($code === 'price_changed') return endCall($c,$s,'The renewal price changed. No charge was made. Please contact the office for a new quote.',true);
            if ($code === 'card_declined') return endCall($c,$s,'The card was declined. No renewal was made. Please hold for the office.',true);
            // Includes transport failures, in-progress, server errors and charged_not_saved.
            return endCall($c,$s,'The renewal result is uncertain. Do not try the charge again. Please contact the office to check the order.',true);
        case 'renew_uncertain':
            return endCall($c,$s,'The renewal result is uncertain. Do not try the charge again. Please contact the office to check the order.',true);
        case 'auto_renew':
            if ($digit === '3') return mainMenu($c,$id,$s);
            if ($digit !== '1' && $digit !== '2') break;
            $s['desired_auto_renew']=$digit === '1'; $s['step']='confirm_auto'; $s['attempts']=0;
            $s['prompt']='To confirm turning auto renew '.($s['desired_auto_renew']?'on':'off').', press 1. To cancel, press 2.';
            return gather($c,$id,$s['prompt']);
        case 'confirm_auto':
            if ($digit === '2') return mainMenu($c,$id,$s,'No change was made.');
            if ($digit !== '1') break;
            $r=api($c,'PATCH','/customers/'.(int)$s['customer'].'/subscriptions/'.(int)$s['subscription'],['auto_renew'=>$s['desired_auto_renew']],$s['session']);
            if ($r['status'] !== 200) {
                $code=$r['error']['code']??'';
                if (in_array($code,['cannot_change_auto_renew','auto_renew_required','card_required','subscription_canceled'],true)) return mainMenu($c,$id,$s,'That change is not available. Please contact the office for assistance.');
                return failApi($c,$s,$r);
            }
            $warnings = array_filter($r['warnings'] ?? [], 'is_string');
            return mainMenu($c,$id,$s,'Auto renew is now '.(!empty($r['data']['auto_renew'])?'on':'off').'. '.implode(' ', $warnings));
    }
    $s['attempts']=($s['attempts']??0)+1;
    if ($s['attempts'] >= bounded($c,'MAX_ATTEMPTS',3,1,10)) return endCall($c,$s,'Too many invalid entries. Goodbye.');
    return gather($c,$id,'Invalid entry. '.($s['prompt']??'Please try again.'),(int)($s['digits']??1));
}
function run(): void {
    $c=config();
    $request = array_merge($_GET, $_POST);
    if (empty($c['WEBRESPONDER_KEY']) || !hash_equals($c['WEBRESPONDER_KEY'], (string)($request['key'] ?? ''))) { http_response_code(403); outputXml(say($c,'This service is unavailable.').'<Hangup/>'); return; }
    if (empty($c['DV_API_KEY']) || empty($c['WEBRESPONDER_URL']) || empty($c['STATE_DIR']) || !extension_loaded('curl')) { http_response_code(503); outputXml(say($c,'This service is unavailable.').'<Hangup/>'); return; }
    $dir=$c['STATE_DIR']; if (!is_dir($dir)) @mkdir($dir,0700,true);
    $id=(string)($request['state']??'');
    if ($id === '') $id=bin2hex(random_bytes(32));
    elseif (!preg_match('/^[a-f0-9]{64}$/D',$id)) { http_response_code(400); outputXml('<Hangup/>'); return; }
    if (empty($request['state']) && random_int(1, 20) === 1) {
        foreach (glob($dir.'/*.json') ?: [] as $old) {
            if (filemtime($old) >= time()-86400) continue;
            $stale = @fopen($old, 'r+');
            if ($stale && flock($stale, LOCK_EX | LOCK_NB)) { @unlink($old); flock($stale, LOCK_UN); }
            if ($stale) fclose($stale);
        }
    }
    $file=$dir.'/'.$id.'.json'; $existing = is_file($file);
    if (!$existing && isset($request['state'])) { http_response_code(410); outputXml(say($c,'This call session has expired.').'<Hangup/>'); return; }
    $f=@fopen($file,'c+');
    if (!$f || !flock($f, LOCK_EX)) { http_response_code(503); outputXml('<Hangup/>'); return; }
    try {
        $raw=stream_get_contents($f); $s=$raw ? json_decode($raw,true) : [];
        if (!is_array($s)) throw new RuntimeException('Invalid state');
        if (!empty($s['updated']) && $s['updated'] < time()-1800) { $body=endCall($c,$s,'This call session has expired. Please call again.'); }
        else {
            $checkpoint = function(array $state) use ($f): void {
                $state['updated']=time(); $encoded=json_encode($state, JSON_THROW_ON_ERROR);
                rewind($f);
                if (!ftruncate($f,0) || fwrite($f,$encoded) !== strlen($encoded) || !fflush($f)) throw new RuntimeException('Cannot persist call state');
                if (function_exists('fsync') && !fsync($f)) throw new RuntimeException('Cannot sync call state');
            };
            $body=processCall($c,$id,$s,$request,$checkpoint);
        }
        $s['updated']=time(); rewind($f); ftruncate($f,0); fwrite($f,json_encode($s,JSON_THROW_ON_ERROR)); fflush($f);
        logEvent($c,'debug','Web Responder request processed.');
        outputXml($body);
    } catch (Throwable $e) { logEvent($c,'error','Call processing failed.'); http_response_code(500); outputXml(say($c,'Sorry, this service is unavailable.').'<Hangup/>'); }
    finally { flock($f,LOCK_UN); fclose($f); }
}
