<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;

$tok = str_repeat('ab12', 16);

it('redacts the device token in an APNs path, also inside text', function () use ($tok) {
    $r = new Redactor;

    expect($r->url("https://api.push.apple.com/3/device/{$tok}"))->toBe('https://api.push.apple.com/3/device/[redacted]')
        ->and($r->string("POST /3/device/{$tok} failed"))->toBe('POST /3/device/[redacted] failed');
});

it('redacts sensitive headers and leaves others', function () {
    $out = (new Redactor)->headers([
        'authorization' => 'bearer abc.def.ghi', 'apns-channel-id' => 'dHN0LXNyY2g=', 'cookie' => 'a=b',
        'proxy-authorization' => 'Basic xyz', 'apns-priority' => '10', 'content-type' => 'application/json',
    ]);

    expect($out)->toBe([
        'authorization' => '[redacted]', 'apns-channel-id' => '[redacted]', 'cookie' => '[redacted]',
        'proxy-authorization' => '[redacted]', 'apns-priority' => '10', 'content-type' => 'application/json',
    ]);
});

it('redacts bearer values, JWTs, PEM blocks and credential JSON fields in free text', function () {
    $r = new Redactor;
    $jwt = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJhYmMifQ.c2lnbmF0dXJl';

    expect($r->string("Authorization: Bearer {$jwt}"))->not->toContain('eyJ')
        ->and($r->string("token {$jwt} here"))->toBe('token [redacted:jwt] here')
        ->and($r->string("x -----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY----- y"))->toBe('x [redacted:key] y')
        ->and($r->string('{"access_token":"ya29.SECRET","expires_in":3600}'))->toBe('{"access_token":"[redacted]","expires_in":3600}')
        ->and($r->string('https://h/x?access_token=SECRET&a=1'))->toBe('https://h/x?access_token=[redacted]&a=1');
});

it('redacts runtime secrets and their encoded forms', function () {
    $r = (new Redactor)->withSecrets(['RAW/SECRET+VALUE=1', "line-one-secret-xxxx\nline-two-secret-yyyy"]);

    expect($r->string('a RAW/SECRET+VALUE=1 b'))->not->toContain('SECRET+VALUE')
        ->and($r->string(rawurlencode('RAW/SECRET+VALUE=1')))->not->toContain('SECRET')
        ->and($r->string('{"k":"RAW\/SECRET+VALUE=1"}'))->not->toContain('SECRET')
        ->and($r->string(base64_encode('RAW/SECRET+VALUE=1')))->not->toContain(base64_encode('RAW'))
        ->and($r->string('only line-two-secret-yyyy leaked'))->not->toContain('yyyy');
});

it('redacts provider messages that echo a token', function () use ($tok) {
    $msg = "The registration token {$tok}:APA91bOpaque is not a valid FCM registration token";

    expect((new Redactor)->providerMessage($msg))->toBe('The registration token [redacted:token] is not a valid FCM registration token');
});

it('walks nested structures, keys and throwables', function () use ($tok) {
    $r = (new Redactor)->withSecrets(['canary-secret-1']);
    $out = $r->value([
        'error' => ['message' => "bad token {$tok}", 'status' => 'INVALID_ARGUMENT'],
        'device_token' => 'whatever', 'list' => ['x canary-secret-1 y', 3, null, true],
        'ex' => new RuntimeException('boom canary-secret-1'), 'obj' => new stdClass,
    ]);

    $json = json_encode($out, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain($tok)->not->toContain('canary-secret-1')->not->toContain('whatever')
        ->and($out['error']['status'])->toBe('INVALID_ARGUMENT')
        ->and($out['obj'])->toBe('[stdClass]');
});

it('builds safe arrays for requests and responses', function () use ($tok) {
    $r = new Redactor;
    $req = $r->request(new Request('1', 'POST', "https://api.push.apple.com/3/device/{$tok}", ['authorization' => 'bearer zzzzzz'], '{"aps":{"alert":"private text"}}'));
    $res = $r->response(new Response(400, ['x' => 'y'], '{"error":{"message":"token '.$tok.' invalid"}}'));

    expect(json_encode([$req, $res]))->not->toContain($tok)->not->toContain('zzzzzz')->not->toContain('private text')
        ->and($req['body_bytes'])->toBe(strlen('{"aps":{"alert":"private text"}}'));
});

it('is idempotent', function () use ($tok) {
    $r = (new Redactor)->withSecrets(['secret', 'redacted', 'canary-xyz-123']);
    $input = [
        "https://h/3/device/{$tok}?token=abc", 'Bearer abcdef123456', 'canary-xyz-123 and secret',
        'k' => ['message' => "echo {$tok}"], 'authorization' => 'x',
    ];
    $once = $r->value($input);

    expect($r->value($once))->toBe($once)
        ->and($r->providerMessage($r->providerMessage("a {$tok}")))->toBe($r->providerMessage("a {$tok}"));
});

it('adds secrets to the ones registered earlier instead of replacing them', function () {
    $r = (new Redactor(['first-secret-value']))->withSecrets(['second-secret-value']);

    expect($r->string('a first-secret-value b second-secret-value c'))->toBe('a [redacted:secret] b [redacted:secret] c');
});

it('registers a secret only from the minimum length of 6 characters, after trimming', function (string $secret, string $text, string $expected) {
    expect((new Redactor)->withSecrets([$secret])->string($text))->toBe($expected);
})->with([
    '5 characters: ignored' => ['abcde', 'x abcde y', 'x abcde y'],
    '6 characters: the boundary, redacted' => ['abcdef', 'x abcdef y', 'x [redacted:secret] y'],
    'surrounding spaces are trimmed' => ["  padded-secret \t", 'x padded-secret y', 'x [redacted:secret] y'],
    'only spaces around a short value: ignored' => ['  abcde  ', 'x abcde y', 'x abcde y'],
    'a placeholder is never a needle' => ['[redacted:secret]', 'x [redacted:secret] y', 'x [redacted:secret] y'],
]);

it('skips a short or placeholder secret and still registers the ones after it', function () {
    $r = (new Redactor)->withSecrets(['abc', 'Bearer', 'later-secret-value']);

    expect($r->string('abc later-secret-value'))->toBe('abc [redacted:secret]')
        ->and((new Redactor)->withSecrets(["ab\nlong-line-secret"])->string('x long-line-secret y'))->toBe('x [redacted:secret] y');
});

it('replaces a multi-line secret as one block where it appears whole, and each line elsewhere', function () {
    $r = (new Redactor)->withSecrets(["first-line-xx\nsecond-line-yy"]);

    expect($r->string("<first-line-xx\nsecond-line-yy>"))->toBe('<[redacted:secret]>')
        ->and($r->string('only second-line-yy here'))->toBe('only [redacted:secret] here');
});

it('replaces the longest needle first, so a secret that contains another leaves nothing behind', function () {
    $r = (new Redactor)->withSecrets(['abcdef', 'abcdef-ghij-klmn']);

    expect($r->string('x abcdef-ghij-klmn y abcdef z'))->toBe('x [redacted:secret] y [redacted:secret] z');
});

it('redacts every encoded form of a registered secret', function (string $form) {
    $r = (new Redactor)->withSecrets(['my secret/value+1']);

    expect($r->string("a {$form} b"))->toBe('a [redacted:secret] b');
})->with([
    'raw' => ['my secret/value+1'],
    'rawurlencode (%20)' => ['my%20secret%2Fvalue%2B1'],
    'urlencode (+)' => ['my+secret%2Fvalue%2B1'],
    'JSON-escaped slash' => ['my secret\/value+1'],
    'base64' => [base64_encode('my secret/value+1')],
]);

it('redacts a numeric secret (array keys of digits are integers in PHP)', function () {
    expect((new Redactor)->withSecrets(['12345678'])->string('pin 12345678 ok'))->toBe('pin [redacted:secret] ok');
});

it('redacts a bearer value from its minimum length of 4, in any casing', function (string $in, string $out) {
    expect((new Redactor)->string($in))->toBe($out);
})->with([
    'exactly 4 characters' => ['Authorization: Bearer abcd', 'Authorization: Bearer [redacted]'],
    '3 characters: left' => ['Authorization: Bearer abc', 'Authorization: Bearer abc'],
    'lower case' => ['bearer ya29.a0-x_y~z+/=', 'Bearer [redacted]'],
    'upper case' => ['BEARER abcd1234 tail', 'Bearer [redacted] tail'],
    'not a word on its own' => ['xbearer abcd', 'xbearer abcd'],
]);

it('redacts JWTs, PEM keys, device paths, credential JSON fields, query secrets and URL user info exactly', function (string $in, string $out) {
    expect((new Redactor)->string($in))->toBe($out);
})->with([
    'JWT with an empty signature' => ['t=eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0. end', 't=[redacted:jwt] end'],
    'JWT header shorter than 5 characters: not a JWT' => ['eyJabcd.abcde.x', 'eyJabcd.abcde.x'],
    'PEM cut before its END line' => ["x -----BEGIN EC PRIVATE KEY-----\nMIIabc\nMIIdef", 'x [redacted:key]'],
    'PEM on a single line' => ['-----BEGIN PRIVATE KEY-----MIIabc-----END PRIVATE KEY----- tail', '[redacted:key] tail'],
    'APNs path, 64 hex' => ['/3/device/'.str_repeat('0f', 32), '/3/device/[redacted]'],
    'APNs path, 160 hex, query kept' => ['POST /3/device/'.str_repeat('a9', 80).'?x=1 sent', 'POST /3/device/[redacted]?x=1 sent'],
    'FCM registration path' => ['/registrations/abc:def/topicSubscriptions', '/registrations/[redacted]/topicSubscriptions'],
    'nested JSON keys, any casing' => ['{"a":{"Client_Secret":"s1","AccessToken":"t2","n":1},"l":[{"x-api_key":"k","RECEIPT":"r"}]}', '{"a":{"Client_Secret":"[redacted]","AccessToken":"[redacted]","n":1},"l":[{"x-api_key":"[redacted]","RECEIPT":"[redacted]"}]}'],
    'JSON value with an escaped quote' => ['{"token":"a\"b","ok":"y"}', '{"token":"[redacted]","ok":"y"}'],
    'query parameter, any casing' => ['https://h/x?Signature=abc&id=1&api_key=z', 'https://h/x?Signature=[redacted]&id=1&api_key=[redacted]'],
    'URL user info' => ['see https://user:pa55@host.test/path now', 'see https://[redacted]@host.test/path now'],
    'URL user info with an empty password' => ['http://u:@h.test/', 'http://[redacted]@h.test/'],
]);

it('returns text without secrets byte for byte, and redacting twice equals redacting once', function (string $text) {
    $r = (new Redactor)->withSecrets(['registered-secret']);

    expect($r->string($text))->toBe($text)
        ->and($r->providerMessage($text))->toBe($text);
})->with([
    'plain' => ['APNs said 400 BadDeviceToken'],
    'unicode' => ["Città – 東京 ✓ \u{1F600}"],
    'short bearer' => ['Bearer x'],
    'apns path without token' => ['/3/device/'],
    'json without credentials' => ['{"aps":{"alert":"hi"},"count":3}'],
]);

it('is idempotent on every pattern', function (string $text) {
    $r = (new Redactor)->withSecrets(['registered-secret']);
    $once = $r->string($text);

    expect($r->string($once))->toBe($once)
        ->and($r->providerMessage($r->providerMessage($text)))->toBe($r->providerMessage($text));
})->with([
    ['Bearer abcd1234 and registered-secret'],
    ['https://user:pw@h.test/3/device/'.str_repeat('ab', 32).'?token=x'],
    ["-----BEGIN PRIVATE KEY-----\nMII\n-----END PRIVATE KEY----- eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.sig"],
    ['{"refresh_token":"abc","password":"p"}'],
]);

it('masks headers whose name contains a credential word, in any casing and with dashes', function (string $name) {
    expect((new Redactor)->headers([$name => 'plain-value', 'apns-priority' => '10']))
        ->toBe([$name => '[redacted]', 'apns-priority' => '10']);
})->with([
    'X-Device-Token', 'Client-Secret', 'Password', 'Passwd', 'Private-Key', 'Authorization', 'Cookie',
    'X-Receipt', 'Apns-Channel-Id', 'X-Credential', 'X-Api-Key', 'X-ApiKey', 'X-Bearer', 'X-Goog-User-Project',
]);

it('accepts header names made of digits', function () {
    expect((new Redactor)->headers(['0' => 'Bearer abcd1234', '42' => 'v']))->toBe([0 => 'Bearer [redacted]', 42 => 'v']);
});

it('masks array values under a credential key at any depth, keeps the keys and leaves scalars untouched', function () {
    $out = (new Redactor)->value([
        'level' => ['X-Goog-Api-Client' => 'abc', 'apiKey' => 'AIza', 'name' => 'ok'],
        'n' => 3, 'f' => 1.5, 'b' => false, 'z' => null, 'token_count' => 7,
    ]);

    expect($out)->toBe([
        'level' => ['X-Goog-Api-Client' => '[redacted]', 'apiKey' => '[redacted]', 'name' => 'ok'],
        'n' => 3, 'f' => 1.5, 'b' => false, 'z' => null, 'token_count' => 7,
    ]);
});

it('returns null, booleans, integers and floats unchanged', function (mixed $scalar) {
    expect((new Redactor)->value($scalar))->toBe($scalar);
})->with([[null], [true], [false], [0], [42], [1.5]]);

it('treats provider message fields as provider messages, whatever their casing', function (string $key) {
    $opaque = str_repeat('Z9', 20);

    expect((new Redactor)->value([$key => "echo {$opaque}", 'note' => "echo {$opaque}"]))
        ->toBe([$key => 'echo [redacted:token]', 'note' => "echo {$opaque}"]);
})->with(['message', 'Message', 'error', 'error_description', 'detail', 'details', 'status_message', 'reason', 'description']);

it('builds the exact safe array of a request', function () {
    $tok = str_repeat('cd', 32);
    $out = (new Redactor)->request(new Request('req-1', 'POST', "https://api.push.apple.com/3/device/{$tok}", ['Authorization' => 'bearer x', 'apns-topic' => 'com.example.App'], '{"aps":{}}', 2500));

    expect($out)->toBe([
        'id' => 'req-1', 'method' => 'POST', 'url' => 'https://api.push.apple.com/3/device/[redacted]',
        'headers' => ['Authorization' => '[redacted]', 'apns-topic' => 'com.example.App'],
        'body_bytes' => 10, 'timeout_ms' => 2500,
    ])->and((new Redactor)->value(new Request('r', 'GET', 'https://h.test/')))->toBe((new Redactor)->request(new Request('r', 'GET', 'https://h.test/')));
});

it('builds the exact safe array of a response and keeps exactly the first 512 bytes of the body', function () {
    $body = str_repeat('ab ', 200);
    $out = (new Redactor)->response(new Response(503, ['retry-after' => '5', 'set-cookie' => 's=1'], $body, '1.1'));

    expect($out)->toBe([
        'status' => 503, 'http_version' => '1.1',
        'headers' => ['retry-after' => '5', 'set-cookie' => '[redacted]'],
        'body' => substr($body, 0, 512),
    ])->and(strlen($out['body']))->toBe(512)
        ->and((new Redactor)->value(new Response(204)))->toBe(['status' => 204, 'http_version' => '2', 'headers' => [], 'body' => '']);
});

it('builds the exact safe array of a throwable chain', function () {
    $inner = new LogicException('inner Bearer abcd1234', 3);
    $line = __LINE__ + 1;
    $outer = new RuntimeException('outer', 7, $inner);
    $innerLine = $inner->getLine();

    $expected = [
        'class' => RuntimeException::class, 'message' => 'outer', 'code' => 7, 'location' => "RedactorTest.php:{$line}",
        'previous' => ['class' => LogicException::class, 'message' => 'inner Bearer [redacted]', 'code' => 3, 'location' => "RedactorTest.php:{$innerLine}"],
    ];

    expect((new Redactor)->throwable($outer))->toBe($expected)
        ->and((new Redactor)->value($outer))->toBe($expected);
});

it('redacts a Stringable as a provider message and reduces other objects to their class name', function () {
    $opaque = str_repeat('Q7', 20);
    $stringable = new class($opaque) implements Stringable
    {
        public function __construct(private string $v) {}

        public function __toString(): string
        {
            return "id {$this->v}";
        }
    };

    expect((new Redactor)->value($stringable))->toBe('id [redacted:token]')
        ->and((new Redactor)->value(new ArrayObject))->toBe('[ArrayObject]');
});

it('fails closed: when the regex engine gives up, the result is empty, never the unredacted text', function () {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $r = new Redactor;
        // At this limit only the URL user-info rule (the last rule of string()) gives up on this input, JIT or not.
        $userInfo = @$r->string('https://u:p@h');
        $plain = $r->string('plain words only');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($userInfo)->toBe('')->and($plain)->toBe('plain words only');
});

it('redacts in JSON text every credential-looking key it redacts in an array (one list for both)', function (string $key) {
    $redactor = new Redactor;
    $json = '{"'.$key.'":"s3cr3t-value-123","kept":"visible"}';

    // The array rule and the text rule must agree: the same structure logged as text must not leak.
    expect($redactor->value([$key => 's3cr3t-value-123', 'kept' => 'visible']))->not->toContain('s3cr3t-value-123')
        ->and($redactor->string($json))->not->toContain('s3cr3t-value-123')->toContain('"kept":"visible"');
})->with([
    'token', 'access_token', 'client_secret', 'password', 'passwd', 'private_key', 'authorization', 'cookie',
    'receipt', 'channel_id', 'credential', 'credentials', 'api_key', 'apiKey', 'api-key', 'bearer', 'x-goog-api-key',
]);

it('cuts a long provider body without splitting a multi-byte character (the record must stay encodable)', function () {
    // 511 bytes of ASCII, then two-byte characters: a cut at 512 bytes falls in the middle of the first one.
    $prefix = str_repeat('ab ', 170).'a';   // short words: a long run of letters would be redacted as a token
    $body = $prefix.str_repeat('é', 40);
    $record = (new Redactor)->response(new Response(500, [], $body));

    expect(preg_match('//u', $record['body']))->toBe(1)
        ->and(strlen($prefix))->toBe(511)
        ->and($record['body'])->toBe($prefix)
        ->and(json_encode($record))->toBeString();
});
