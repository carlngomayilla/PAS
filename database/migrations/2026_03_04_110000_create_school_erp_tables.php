<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('school_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('school_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_level_id')->constrained('school_levels')->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['school_level_id', 'name'], 'school_classes_level_name_unique');
        });

        Schema::create('school_guardians', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('school_students', function (Blueprint $table): void {
            $table->id();
            $table->string('matricule')->unique();
            $table->string('full_name');
            $table->enum('gender', ['M', 'F', 'OTHER'])->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'LEFT'])->default('ACTIVE');
            $table->foreignId('guardian_id')->nullable()->constrained('school_guardians')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('school_fee_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('school_level_id')->nullable()->constrained('school_levels')->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->decimal('registration_fee', 14, 2)->default(0);
            $table->decimal('tuition_total', 14, 2)->default(0);
            $table->decimal('other_fee', 14, 2)->default(0);
            $table->unsignedInteger('installment_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('school_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->date('enrolled_on');
            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'LEFT'])->default('ACTIVE');
            $table->timestamps();
            $table->unique(['student_id', 'school_year_id'], 'school_enrollment_student_year_unique');
        });

        Schema::create('school_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('school_enrollments')->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->enum('status', ['UNPAID', 'PARTIAL', 'PAID'])->default('UNPAID');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'status'], 'school_invoices_student_status_index');
        });

        Schema::create('school_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('school_invoices')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 14, 2);
            $table->date('due_date');
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->boolean('is_settled')->default(false);
            $table->timestamps();
            $table->index(['invoice_id', 'due_date'], 'school_invoice_lines_invoice_due_index');
        });

        Schema::create('school_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_no')->unique();
            $table->foreignId('invoice_id')->constrained('school_invoices')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('school_students')->restrictOnDelete();
            $table->date('payment_date');
            $table->enum('method', ['CASH', 'BANK', 'MOBILE']);
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->string('received_by')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'payment_date'], 'school_payments_invoice_date_index');
        });

        Schema::create('school_chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('account_type', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']);
            $table->enum('normal_side', ['DEBIT', 'CREDIT']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('school_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_no')->unique();
            $table->date('entry_date');
            $table->string('description');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('total_debit', 14, 2);
            $table->decimal('total_credit', 14, 2);
            $table->timestamps();
            $table->index(['entry_date', 'id'], 'school_journal_entries_date_id_index');
        });

        Schema::create('school_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('school_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('school_chart_accounts')->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('line_description')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'entry_id'], 'school_journal_lines_account_entry_index');
        });

        $year = now()->year;
        DB::table('school_years')->insert([
            'name' => $year.'-'.($year + 1),
            'starts_on' => $year.'-09-01',
            'ends_on' => ($year + 1).'-07-31',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $levels = ['6e', '5e', '4e', '3e'];
        foreach ($levels as $levelName) {
            DB::table('school_levels')->insert([
                'name' => $levelName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $levelIds = DB::table('school_levels')->pluck('id', 'name');
        foreach (['6e', '5e', '4e', '3e'] as $levelName) {
            DB::table('school_classes')->insert([
                'school_level_id' => $levelIds[$levelName],
                'name' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('school_fee_plans')->insert([
            'name' => 'Bareme Standard College',
            'school_level_id' => $levelIds['6e'],
            'registration_fee' => 50000,
            'tuition_total' => 250000,
            'other_fee' => 0,
            'installment_count' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('school_chart_accounts')->insert([
            [
                'code' => '101000',
                'name' => 'Capital social',
                'account_type' => 'EQUITY',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '411000',
                'name' => 'Clients / Parents',
                'account_type' => 'ASSET',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '421000',
                'name' => 'Personnel remunerations dues',
                'account_type' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '431000',
                'name' => 'Organismes sociaux',
                'account_type' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '445600',
                'name' => 'TVA deductible',
                'account_type' => 'ASSET',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '445700',
                'name' => 'TVA collectee',
                'account_type' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '512000',
                'name' => 'Banque',
                'account_type' => 'ASSET',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '512100',
                'name' => 'Mobile money',
                'account_type' => 'ASSET',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '531000',
                'name' => 'Caisse',
                'account_type' => 'ASSET',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '601000',
                'name' => 'Achats',
                'account_type' => 'EXPENSE',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '607000',
                'name' => 'Achats consommables',
                'account_type' => 'EXPENSE',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '641000',
                'name' => 'Charges de personnel',
                'account_type' => 'EXPENSE',
                'normal_side' => 'DEBIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '706000',
                'name' => 'Prestations de services',
                'account_type' => 'REVENUE',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '707000',
                'name' => 'Ventes de produits',
                'account_type' => 'REVENUE',
                'normal_side' => 'CREDIT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_journal_lines');
        Schema::dropIfExists('school_journal_entries');
        Schema::dropIfExists('school_chart_accounts');
        Schema::dropIfExists('school_payments');
        Schema::dropIfExists('school_invoice_lines');
        Schema::dropIfExists('school_invoices');
        Schema::dropIfExists('school_enrollments');
        Schema::dropIfExists('school_fee_plans');
        Schema::dropIfExists('school_students');
        Schema::dropIfExists('school_guardians');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('school_levels');
        Schema::dropIfExists('school_years');
    }
};
