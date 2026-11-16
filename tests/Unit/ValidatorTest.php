<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private function errors(array $data, array $rules): array
    {
        try {
            Validator::validate($data, $rules);
            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    public function testRequiredIsEnforced(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => ''], ['name' => 'required|string']));
        $this->assertArrayHasKey('name', $this->errors([], ['name' => 'required']));
    }

    public function testOptionalEmptyValuesAreNull(): void
    {
        $out = Validator::validate(['a' => '', 'b' => ''], ['a' => 'nullable|integer', 'b' => 'nullable|string']);
        $this->assertNull($out['a']);
        $this->assertNull($out['b']);
    }

    public function testTypesAreNormalised(): void
    {
        $out = Validator::validate(['n' => '42', 'flag' => 'on', 'off' => ''], ['n' => 'integer', 'flag' => 'boolean', 'off' => 'nullable|boolean']);
        $this->assertSame(42, $out['n']);
        $this->assertSame(1, $out['flag']);
        $this->assertSame(0, $out['off']);
    }

    public function testOnlyValidatedKeysAreReturned(): void
    {
        $out = Validator::validate(['name' => 'x', 'is_admin' => '1'], ['name' => 'required']);
        $this->assertSame(['name' => 'x'], $out);
    }

    public function testNumericMinMax(): void
    {
        $this->assertNotEmpty($this->errors(['q' => '5'], ['q' => 'numeric|min:10']));
        $this->assertEmpty($this->errors(['q' => '10'], ['q' => 'numeric|min:10']));
        $this->assertNotEmpty($this->errors(['s' => 'abcdef'], ['s' => 'string|max:5']));
    }

    public function testRegexMayContainPipes(): void
    {
        $this->assertEmpty($this->errors(['c' => 'B'], ['c' => 'required|regex:/^(A|B)$/']));
        $this->assertNotEmpty($this->errors(['c' => 'C'], ['c' => 'required|regex:/^(A|B)$/']));
    }

    public function testPasswordPolicyAndConfirmation(): void
    {
        $this->assertNotEmpty($this->errors(['p' => 'short1'], ['p' => 'password']));
        $this->assertNotEmpty($this->errors(['p' => 'lettersonly'], ['p' => 'password']));
        $this->assertEmpty($this->errors(['p' => 'Valid1234', 'p_confirmation' => 'Valid1234'], ['p' => 'password|confirmed']));
        $this->assertNotEmpty($this->errors(['p' => 'Valid1234', 'p_confirmation' => 'other'], ['p' => 'password|confirmed']));
    }

    public function testDateAndIn(): void
    {
        $this->assertNotEmpty($this->errors(['d' => '2026-02-30'], ['d' => 'date']));
        $this->assertEmpty($this->errors(['d' => '2026-02-28'], ['d' => 'date']));
        $this->assertNotEmpty($this->errors(['s' => 'x'], ['s' => 'in:a,b']));
    }

    public function testArrayItemRules(): void
    {
        $errors = $this->errors(['ids' => ['1', 'abc']], ['ids' => 'array', 'ids.*' => 'integer']);
        $this->assertArrayHasKey('ids.1', $errors);
    }
}
