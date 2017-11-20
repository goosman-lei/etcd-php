# php-etcd

client of etcd for php language

Inspired by [linkorb/etcd-php](http://github.com/goosman-lei/etcd-php)

* persistent connect in multi request

* one connect service multi times

* raw http-response parse, will maxmized reduce real-time

* In my test(1000 times getNode)

real-time: 800ms

cpu usr-time: 180ms

cpu sys-time: 35ms

## example code

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

$etcd = Etcd\KeysApi::create();

$info = $etcd->rmdir('/etcd-php-test');
echo "rmdir /etcd-php-test\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$info = $etcd->mkdir('/etcd-php-test/withttl', 1);
echo "mkdir /etcd-php-test/withttl 1\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$info = $etcd->getNode('/etcd-php-test/withttl');
echo "getnode /etcd-php-test/withttl\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

echo "sleep 2\n";
sleep(2);

$info = $etcd->getNode('/etcd-php-test/withttl');
echo "getnode /etcd-php-test/withttl\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$info = $etcd->set('/etcd-php-test/dir-parent/dir-child/value-key', 'Hello value-key');
echo "set /etcd-php-test/dir-parent/dir-child/value-key 'Hello value-key'\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$info = $etcd->set('/etcd-php-test/dir-parent', 'Hello dir-parent');
echo "set /etcd-php-test/dir-parent 'Hello dir-parent'\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$info = $etcd->get('/etcd-php-test/dir-parent/dir-child/value-key');
echo "set /etcd-php-test/dir-parent/dir-child/value-key\n";
echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
```
