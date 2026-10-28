<?php
/**
 * FREE / demo build smoke test. Validates the invariants that make this a safe read-only distribution:
 * no write tools ship, the premium engines are inert stubs, the universal key works, the Prompts tab
 * shows cards without bodies. Run on every supported PHP: docker run --rm -v "$PWD":/app -w /app php:7.1-cli php tests/run_free.php
 */
error_reporting(E_ALL);
require __DIR__ . '/../src/mcp/autoload.php';

use OcMcp\ToolRegistry;
use OcMcp\Tool;
use OcMcp\License;
use OcMcp\Version;
use OcMcp\ChangeJournal;
use OcMcp\PromptLibrary;

$pass = 0;
$fail = 0;

function check(string $name, bool $cond): void {
    global $pass, $fail;

    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}

// ---- the registry is read-only
$reg = ToolRegistry::createDefault();
$ref = new ReflectionClass($reg);
$p = $ref->getProperty('tools');
$p->setAccessible(true);
$tools = $p->getValue($reg);

check('registry has exactly 18 tools', count($tools) === 18);
$nonRead = array_filter($tools, function ($t) { return $t->level() !== Tool::LEVEL_READ; });
check('every registered tool is read-level', count($nonRead) === 0);

// ---- no write tool class ships at all
$removed = ['BulkUpdateProducts', 'UpdateProduct', 'CopyProduct', 'ManageRecords', 'ManageCustomer', 'ManageReview',
            'UpdateSettings', 'ImportData', 'CreateOrder', 'UpdateOrderStatus', 'PrepareMail', 'SendMail', 'CancelMail',
            'ListMails', 'SetImages', 'DeleteImage', 'EditImage', 'CreateUploadLink', 'UndoChanges', 'RunPrompt',
            'ListPrompts', 'ListChanges'];
foreach ($removed as $c) {
    check("tool class removed: $c", !class_exists('OcMcp\\Tools\\' . $c, true) && !is_file(__DIR__ . '/../src/mcp/Tools/' . $c . '.php'));
}
check('OrderBuilder removed', !class_exists('OcMcp\\OrderBuilder', true) && !is_file(__DIR__ . '/../src/mcp/OrderBuilder.php'));

// ---- SaveContent / ImportImage are helper-only (no Tool subclass, keep the read-path helpers)
check('SaveContent is a helper, not a Tool', class_exists('OcMcp\\Tools\\SaveContent') && !is_subclass_of('OcMcp\\Tools\\SaveContent', Tool::class));
check('SaveContent::MAX_ITEMS present', \OcMcp\Tools\SaveContent::MAX_ITEMS === 100);
check('SaveContent::categoryLink works', \OcMcp\Tools\SaveContent::categoryLink(['entity' => 'product'])['table'] === 'product_to_category');
check('ImportImage is a helper, not a Tool', class_exists('OcMcp\\Tools\\ImportImage') && !is_subclass_of('OcMcp\\Tools\\ImportImage', Tool::class));

// ---- the universal free key is valid on any domain
$lic = new License();
check('free key valid on domain A', $lic->check(License::FREE_KEY, 'a-shop.com')['valid'] === true);
check('free key valid on domain B', $lic->check(License::FREE_KEY, 'other.example.org')['valid'] === true);
check('a random string is not valid', $lic->check('nonsense', 'x.com')['valid'] === false);
check('Version::FREE is true', Version::FREE === true);

// ---- ChangeJournal is an inert stub (rollback IP removed), constants kept
check('ChangeJournal::TABLE kept', ChangeJournal::TABLE === 'mcp_change');
check('ChangeJournal::isHighRisk still works', ChangeJournal::isHighRisk('product') === true && ChangeJournal::isHighRisk('nope') === false);
$rcj = new ReflectionClass(ChangeJournal::class);
$jrnl = $rcj->newInstanceWithoutConstructor();
check('ChangeJournal::rollback is inert', $jrnl->rollback('x', true)['ok'] === false);
check('ChangeJournal::snapshot is inert', $jrnl->snapshot('product', '1=1') === []);

// ---- PromptLibrary: showcase cards with NO bodies, nothing exposed to the AI
$rpl = new ReflectionClass(PromptLibrary::class);
$lib = $rpl->newInstanceWithoutConstructor();
$lp = $rpl->getProperty('lang');
$lp->setAccessible(true);
$lp->setValue($lib, 'uk');
$cards = $lib->all(true);
check('Prompts tab shows 6 showcase cards', count($cards) === 6);
$bodies = array_sum(array_map(function ($c) { return strlen($c['body']); }, $cards));
check('no prompt bodies ship (IP removed)', $bodies === 0);
check('PromptLibrary::defaults() is empty', PromptLibrary::defaults() === []);
check('MCP prompts/list is empty (AI gets nothing)', $lib->mcpList() === []);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
