<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\EventChain;
use App\Domain\MailAccess;
use App\Domain\MailReference;
use App\Domain\MailWorkflow;
use App\Domain\UnitTree;
use App\Security\CurrentUser;
use App\Security\Role;
use PHPUnit\Framework\TestCase;

final class MailDomainTest extends TestCase
{
    private function user(Role $role, int $clearance = 0, array $scope = [10]): CurrentUser
    {
        return new CurrentUser(1, 'Agent', 'a@b.c', $role, $scope[0] ?? null, 'Unité', $clearance, $scope);
    }

    private function mail(int $level = 0, int $unit = 99): array
    {
        return ['confidentiality' => $level, 'unit_id' => $unit, 'registered_unit_id' => 98, 'created_by' => 7];
    }

    public function testReferenceFormat(): void
    {
        $this->assertSame('DGDA/ARR/2026/000123', MailReference::format('DGDA', 'in', 2026, 123));
        $this->assertSame('DGDA/DEP/2026/000004', MailReference::format('DGDA', 'out', 2026, 4));
    }

    public function testWorkflow(): void
    {
        $this->assertTrue(MailWorkflow::can('registered', 'assigned'));
        $this->assertTrue(MailWorkflow::can('in_progress', 'answered'));
        $this->assertFalse(MailWorkflow::can('registered', 'answered'));
        $this->assertFalse(MailWorkflow::can('closed', 'in_progress'));
        $this->assertTrue(MailWorkflow::closeNeedsNote('in', 'in_progress'));
        $this->assertFalse(MailWorkflow::closeNeedsNote('in', 'answered'));
        $this->assertTrue(MailWorkflow::isLate('2026-01-01', 'assigned', '2026-01-02'));
        $this->assertFalse(MailWorkflow::isLate('2026-01-01', 'answered', '2026-01-02'));
    }

    public function testUnitSubtree(): void
    {
        $units = [['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => 1], ['id' => 3, 'parent_id' => 2], ['id' => 4, 'parent_id' => null]];
        $ids = UnitTree::subtree($units, 1);
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testDraftCanBeCancelledAndReassignedMailCanBeAnswered(): void
    {
        $this->assertTrue(MailWorkflow::can('draft', 'cancelled'));
        $this->assertFalse(MailWorkflow::can('sent', 'cancelled'));
        $this->assertTrue(MailWorkflow::can('assigned', 'answered'));
        $this->assertTrue(MailWorkflow::isFinal('cancelled'));
        $this->assertFalse(MailWorkflow::isLate('2026-01-01', 'cancelled', '2026-01-02'));
    }

    public function testArchiveFolderIsTheSubDirection(): void
    {
        $units = [['id' => 1, 'parent_id' => null, 'kind' => 'direction'], ['id' => 2, 'parent_id' => 1, 'kind' => 'sous_direction'],
            ['id' => 3, 'parent_id' => 2, 'kind' => 'service'], ['id' => 4, 'parent_id' => 3, 'kind' => 'bureau'], ['id' => 5, 'parent_id' => 1, 'kind' => 'service']];
        $this->assertSame(2, UnitTree::folder($units, 4));
        $this->assertSame(5, UnitTree::folder($units, 5), 'hors sous-direction : son propre dossier');
        $this->assertSame([2, 3, 4], UnitTree::folderUnits($units, 2));
    }

    public function testClearancePrevailsEvenForAdmin(): void
    {
        $this->assertFalse(MailAccess::canRead($this->user(Role::Admin), $this->mail(2), []));
        $this->assertFalse(MailAccess::canAct($this->user(Role::Admin), $this->mail(2)));
        $this->assertTrue(MailAccess::canRead($this->user(Role::Admin, 2), $this->mail(2), []));
    }

    public function testUnitScopeAndCopy(): void
    {
        $agent = $this->user(Role::Agent);
        $this->assertFalse(MailAccess::canRead($agent, $this->mail(), []));
        $this->assertTrue(MailAccess::canRead($agent, $this->mail(0, 10), []));
        $this->assertTrue(MailAccess::canRead($agent, $this->mail(), [10]), 'copie : lecture');
        $this->assertFalse(MailAccess::canAct($agent, $this->mail()), 'copie : pas d\'action');
    }

    public function testAuditorReadsButNeverActs(): void
    {
        $auditor = $this->user(Role::Auditor, 2);
        $this->assertTrue(MailAccess::canRead($auditor, $this->mail(1), []));
        $this->assertFalse(MailAccess::canAct($auditor, $this->mail(1)));
    }

    public function testChainDetectsTampering(): void
    {
        $events = [];
        $prev = EventChain::GENESIS;
        foreach (['registered', 'assigned'] as $i => $a) {
            $at = '2026-01-0' . ($i + 1) . ' 10:00:00';
            $hash = EventChain::seal('cle', $prev, 1, $a, null, 'X', $at, 5, 'Unité', '10.0.0.1');
            $events[] = ['id' => $i + 1, 'mail_id' => 1, 'action' => $a, 'detail' => null, 'user_id' => 5, 'user_name' => 'X', 'unit_name' => 'Unité', 'ip' => '10.0.0.1', 'created_at' => $at, 'prev_hash' => $prev, 'hash' => $hash];
            $prev = $hash;
        }
        $this->assertTrue(EventChain::verify($events, 'cle'));
        $this->assertFalse(EventChain::verify($events, 'autre-cle'), 'chaîne recalculable seulement avec la clé');
        foreach (['action' => 'closed', 'ip' => '10.0.0.2', 'user_id' => 6, 'unit_name' => 'Autre'] as $field => $value) {
            $altered = $events;
            $altered[0][$field] = $value;
            $this->assertFalse(EventChain::verify($altered, 'cle'), $field);
        }
    }

    public function testLegacyHashOnlyAcceptedBeforeTheBoundary(): void
    {
        $at = '2026-01-01 10:00:00';
        $old = ['id' => 1, 'mail_id' => 1, 'action' => 'registered', 'detail' => null, 'user_id' => 5, 'user_name' => 'X', 'unit_name' => null, 'ip' => null, 'created_at' => $at,
            'prev_hash' => EventChain::GENESIS, 'hash' => EventChain::hash(EventChain::GENESIS, 1, 'registered', null, 'X', $at)];
        $this->assertTrue(EventChain::verify([$old], 'cle', 2));
        $this->assertFalse(EventChain::verify([$old], 'cle', 1), 'pas de retour à l\'empreinte sans clé');
    }
}
