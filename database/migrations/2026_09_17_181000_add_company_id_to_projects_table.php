<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->after('organization_id');
            $table->index(['organization_id', 'company_id']);
            $table->foreign(['organization_id', 'company_id'], 'projects_company_tenant_fk')
                ->references(['organization_id', 'id'])->on('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign('projects_company_tenant_fk');
            $table->dropIndex(['organization_id', 'company_id']);
            $table->dropColumn('company_id');
        });
    }
};
