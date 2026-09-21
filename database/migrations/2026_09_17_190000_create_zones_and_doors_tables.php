<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite tenant FKs below require the referenced pair to be
        // unique. Older site schema versions only keyed sites by id.
        Schema::table('sites', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'sites_organization_id_id_unique');
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'devices_organization_id_id_unique');
        });

        Schema::create('zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign(['organization_id'])->references(['id'])->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'site_id'])->references(['organization_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['organization_id', 'site_id', 'name']);
            $table->unique(['organization_id', 'id'], 'zones_organization_id_id_unique');
            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('doors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->uuid('zone_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('name');
            $table->string('direction')->default('unspecified');
            $table->json('lock_configuration')->nullable();
            $table->json('rex_configuration')->nullable();
            $table->json('sensor_configuration')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign(['organization_id'])->references(['id'])->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'site_id'])->references(['organization_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['organization_id', 'zone_id'])->references(['organization_id', 'id'])->on('zones')->nullOnDelete();
            $table->foreign(['organization_id', 'device_id'])->references(['organization_id', 'id'])->on('devices')->nullOnDelete();
            $table->unique(['organization_id', 'site_id', 'name']);
            $table->index(['organization_id', 'enabled']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['zones', 'doors'] as $table) {
                DB::statement("alter table {$table} enable row level security");
                DB::statement("alter table {$table} force row level security");
                DB::statement("create policy {$table}_organization_isolation on {$table} using (organization_id = current_setting('app.current_organization_id', true)::uuid)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doors');
        Schema::dropIfExists('zones');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique('sites_organization_id_id_unique');
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_organization_id_id_unique');
        });
    }
};
