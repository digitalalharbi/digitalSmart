<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Domain\Access\PermissionSet;
use App\Domain\Access\SystemRole;

describe('PermissionSet', function (): void {
    it('reports membership', function (): void {
        $set = new PermissionSet([Permission::ClientsView, Permission::ClientsCreate]);

        expect($set->has(Permission::ClientsView))->toBeTrue()
            ->and($set->has(Permission::ClientsDelete))->toBeFalse();
    });

    it('ignores unknown slugs instead of granting them', function (): void {
        // Stale data must never become an implicit grant.
        $set = PermissionSet::fromSlugs(['clients.view', 'clients.sudo', 'nonsense']);

        expect($set->slugs())->toBe(['clients.view']);
    });

    it('answers hasAny and hasAll correctly', function (): void {
        $set = new PermissionSet([Permission::ProjectsViewAssigned]);

        expect($set->hasAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned))->toBeTrue()
            ->and($set->hasAll(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned))->toBeFalse();
    });

    it('is empty by default', function (): void {
        expect(PermissionSet::empty()->isEmpty())->toBeTrue()
            ->and(PermissionSet::empty()->hasAny(...Permission::cases()))->toBeFalse();
    });
});

describe('SystemRole', function (): void {
    it('grants the owner everything', function (): void {
        expect(SystemRole::Owner->permissions())->toBe(Permission::cases());
    });

    it('never lets a client viewer see other clients or the audit trail', function (): void {
        $permissions = new PermissionSet(SystemRole::ClientViewer->permissions());

        expect($permissions->has(Permission::ClientsView))->toBeFalse()
            ->and($permissions->has(Permission::UsersView))->toBeFalse()
            ->and($permissions->has(Permission::AuditView))->toBeFalse()
            ->and($permissions->has(Permission::ProjectsViewAll))->toBeFalse()
            ->and($permissions->has(Permission::ProjectsViewAssigned))->toBeTrue();
    });

    it('does not let non-admin roles manage workspace members', function (): void {
        foreach ([SystemRole::MediaBuyer, SystemRole::Creative, SystemRole::ClientViewer, SystemRole::ProjectManager] as $role) {
            $permissions = new PermissionSet($role->permissions());

            expect($permissions->has(Permission::WorkspacesManageMembers))
                ->toBeFalse("{$role->value} must not manage members");
        }
    });

    it('limits buyers and creatives to the projects they are assigned to', function (): void {
        foreach ([SystemRole::MediaBuyer, SystemRole::Creative] as $role) {
            $permissions = new PermissionSet($role->permissions());

            expect($permissions->has(Permission::ProjectsViewAll))->toBeFalse()
                ->and($permissions->has(Permission::ProjectsViewAssigned))->toBeTrue();
        }
    });

    it('protects the owner role from deletion', function (): void {
        expect(SystemRole::Owner->isProtected())->toBeTrue()
            ->and(SystemRole::Admin->isProtected())->toBeFalse();
    });

    it('gives every role a label, description and at least one permission', function (): void {
        foreach (SystemRole::cases() as $role) {
            expect($role->label())->not->toBe('')
                ->and($role->description())->not->toBe('')
                ->and($role->permissions())->not->toBeEmpty();
        }
    });
});

describe('Permission catalogue', function (): void {
    it('uses a group.action naming scheme throughout', function (): void {
        foreach (Permission::cases() as $permission) {
            expect($permission->value)->toMatch('/^[a-z_]+\.[a-z_]+$/')
                ->and($permission->group())->toBe(explode('.', $permission->value)[0]);
        }
    });

    it('describes every permission', function (): void {
        foreach (Permission::cases() as $permission) {
            expect($permission->description())->not->toBe('');
        }
    });
});
