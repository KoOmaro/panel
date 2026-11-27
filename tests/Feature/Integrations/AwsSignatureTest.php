<?php

use App\Support\Integrations\Aws\AwsSigner;
use GuzzleHttp\Psr7\Request;

// Vectors from AWS's Signature Version 4 test suite, Copyright Amazon.com, Inc. or its affiliates, Apache License 2.0, as kept in boto/botocore tests/unit/auth/aws4_testsuite.
const SIGV4_SUITE_KEY = 'AKIDEXAMPLE';
const SIGV4_SUITE_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

dataset('sigv4 suite', [
    'get-vanilla' => ['GET', '/', '', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", 'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63', 'host;x-amz-date', '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'],
    'get-vanilla-query-order-key-case' => ['GET', '/', 'Param2=value2&Param1=value1', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/\nParam1=value1&Param2=value2\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '816cd5b414d056048ba4f7c5386d6e0533120fb1fcfa93762cf0fc39e2cf19e0', 'host;x-amz-date', 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500'],
    'get-vanilla-query-unreserved' => ['GET', '/', '-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz=-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/\n-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz=-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", 'c30d4703d9f799439be92736156d47ccfb2d879ddf56f5befa6d1d6aab979177', 'host;x-amz-date', '9c3e54bfcdf0b19771a7f523ee5669cdf59bc7cc0884027167c21bb143a40197'],
    'get-unreserved' => ['GET', '/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', '', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '6a968768eefaa713e2a6b16b589a8ea192661f098f37349f4e2c0082757446f9', 'host;x-amz-date', '07ef7494c76fa4850883e2b006601f940f8a34d404d0cfa977f52a65bbf5f24f'],
    'get-utf8' => ['GET', "/\xe1\x88\xb4", '', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/%E1%88%B4\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '2a0a97d02205e45ce2e994789806b19270cfbbb0921b278ccf58f5249ac42102', 'host;x-amz-date', '8318018e0b0f223aa2bbf98705b62bb787dc9c0e678f255a891fd03141be5d85'],
    'get-vanilla-utf8-query' => ['GET', '/', "\xe1\x88\xb4=bar", ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/\n%E1%88%B4=bar\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", 'eb30c5bed55734080471a834cc727ae56beb50e5f39d1bff6d0d38cb192a7073', 'host;x-amz-date', '2cdec8eed098649ff3a119c94853b13c643bcf08f8b0a1d91e12c9027818dd04'],
    'get-header-value-trim' => ['GET', '/', '', ['Host' => ['example.amazonaws.com'], 'My-Header1' => [' value1'], 'My-Header2' => [' "a   b   c"'], 'X-Amz-Date' => ['20150830T123600Z']], '', "GET\n/\n\nhost:example.amazonaws.com\nmy-header1:value1\nmy-header2:\"a b c\"\nx-amz-date:20150830T123600Z\n\nhost;my-header1;my-header2;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", 'a726db9b0df21c14f559d0a978e563112acb1b9e05476f0a6a1c7d68f28605c7', 'host;my-header1;my-header2;x-amz-date', 'acc3ed3afb60bb290fc8d2dd0098b9911fcaa05412b367055dee359757a9c736'],
    'post-header-key-sort' => ['POST', '/', '', ['Host' => ['example.amazonaws.com'], 'My-Header1' => ['value1'], 'X-Amz-Date' => ['20150830T123600Z']], '', "POST\n/\n\nhost:example.amazonaws.com\nmy-header1:value1\nx-amz-date:20150830T123600Z\n\nhost;my-header1;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '9368318c2967cf6de74404b30c65a91e8f6253e0a8659d6d5319f1a812f87d65', 'host;my-header1;x-amz-date', 'c5410059b04c1ee005303aed430f6e6645f61f4dc9e1461ec8f8916fdf18852c'],
    'post-vanilla' => ['POST', '/', '', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "POST\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '553f88c9e4d10fc9e109e2aeb65f030801b70c2f6468faca261d401ae622fc87', 'host;x-amz-date', '5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b'],
    'post-vanilla-query' => ['POST', '/', 'Param1=value1', ['Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], '', "POST\n/\nParam1=value1\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", '9d659678c1756bb3113e2ce898845a0a79dbbc57b740555917687f1b3340fbbd', 'host;x-amz-date', '28038455d6de14eafc1f9222cf5aa6f1a96197d7deb8263271d420d138af7f11'],
    'post-x-www-form-urlencoded' => ['POST', '/', '', ['Content-Type' => ['application/x-www-form-urlencoded'], 'Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], 'Param1=value1', "POST\n/\n\ncontent-type:application/x-www-form-urlencoded\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\ncontent-type;host;x-amz-date\n9095672bbd1f56dfc5b65f3e153adc8731a4a654192329106275f4c7b24d0b6e", '42a5e5bb34198acb3e84da4f085bb7927f2bc277ca766e6d19c73c2154021281', 'content-type;host;x-amz-date', 'ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a'],
    'post-x-www-form-urlencoded-parameters' => ['POST', '/', '', ['Content-Type' => ['application/x-www-form-urlencoded; charset=utf8'], 'Host' => ['example.amazonaws.com'], 'X-Amz-Date' => ['20150830T123600Z']], 'Param1=value1', "POST\n/\n\ncontent-type:application/x-www-form-urlencoded; charset=utf8\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\ncontent-type;host;x-amz-date\n9095672bbd1f56dfc5b65f3e153adc8731a4a654192329106275f4c7b24d0b6e", '2e1cf7ed91881a30569e46552437e4156c823447bf1781b921b5d486c568dd1c', 'content-type;host;x-amz-date', '1a72ec8f64bd914b0e42e42607c7fbce7fb2c7465f63e3092b3b0d39fa77a6fe'],
]);

test('AWS’s published SigV4 test vectors give the same canonical request, string to sign and signature', function (string $method, string $path, string $query, array $headers, string $body, string $canonical, string $hash, string $signed, string $signature) {
    $at = new DateTimeImmutable('2015-08-30T12:36:00Z');

    [$request, $signedHeaders] = AwsSigner::canonicalRequest($method, $path, $query, $headers, hash('sha256', $body));
    expect($request)->toBe($canonical)
        ->and($signedHeaders)->toBe($signed)
        ->and(AwsSigner::stringToSign($at, AwsSigner::scope($at, 'us-east-1', 'service'), $request))->toBe("AWS4-HMAC-SHA256\n20150830T123600Z\n20150830/us-east-1/service/aws4_request\n".$hash)
        ->and((new AwsSigner(SIGV4_SUITE_KEY, SIGV4_SUITE_SECRET))->authorization($method, $path, $query, $headers, $body, 'us-east-1', 'service', $at))
        ->toBe('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders='.$signed.', Signature='.$signature);
})->with('sigv4 suite');

test('signing a request adds the date and signs only its host, content type and x-amz headers', function () {
    $request = new Request('POST', 'https://example.amazonaws.com/', ['Content-Type' => 'application/x-www-form-urlencoded', 'User-Agent' => 'GuzzleHttp/7', 'Accept' => 'text/xml'], 'Param1=value1');

    $signed = (new AwsSigner(SIGV4_SUITE_KEY, SIGV4_SUITE_SECRET))->sign($request, 'us-east-1', 'service', new DateTimeImmutable('2015-08-30 13:36:00', new DateTimeZone('Europe/London')));

    expect($signed->getHeaderLine('X-Amz-Date'))->toBe('20150830T123600Z')
        ->and($signed->getHeaderLine('Authorization'))->toBe('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a')
        ->and((string) $signed->getBody())->toBe('Param1=value1');
});

test('the signing key is derived from the date, region and service as AWS documents', function () {
    $at = new DateTimeImmutable('2015-08-30T12:36:00Z');

    expect(bin2hex(AwsSigner::signingKey(SIGV4_SUITE_SECRET, $at, 'us-east-1', 'iam')))->toBe('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9');
});
