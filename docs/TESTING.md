# Testing

## Übersicht

Tests laufen mit PHPUnit ohne echten Webserver. Das `tests/bootstrap.php` stellt eine `simulateRequest()`-Funktion bereit, die Requests durch den Router leitet und `status`, `headers` und `body` zurückgibt.

## Tests ausführen

```bash
vendor/bin/phpunit
```

## Neue Tests hinzufügen

Feature-Tests kommen in `tests/Feature/`. Klassen im Namespace `App\Tests\Feature`, Basisklasse `PHPUnit\Framework\TestCase`.

Beispiel:

```php
class MyTest extends \PHPUnit\Framework\TestCase
{
    public function testSomething(): void
    {
        $result = simulateRequest('GET', '/my-route');
        $this->assertSame(200, $result['status']);
    }
}
```

## Konventionen

- Tests sind deterministisch (kein Netzwerk, kein DB-Zugriff in Feature-Tests).
- Jeder neue Route-Handler bekommt mindestens einen Feature-Test.
