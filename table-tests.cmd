set ESSENTIA_FORCE=1
vendor\bin\phpunit tests\TestCase 2>&1 | php tools\phpunit-group.php
