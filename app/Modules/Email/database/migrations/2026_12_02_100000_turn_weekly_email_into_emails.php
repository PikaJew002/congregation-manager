<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Weekly Email module becomes Email: a congregation can have any number of
 * emails, each either recurring (some weekdays at a time) or one-time (a date
 * and time). What stays per congregation is the sender and the shared
 * variables every email can use.
 *
 * Each congregation's weekly email becomes its first recurring email, and its
 * Zoom link becomes a shared variable named zoom_link, so {{zoom_link}} in the
 * old template keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // draft: not sending. scheduled: a recurring email that is on, or a
            // one-time email waiting for send_at. sent: a one-time email whose
            // run has been created.
            $table->string('status')->default('draft');
            $table->string('send_mode')->default('recurring');
            $table->string('subject');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->json('send_days')->nullable();
            $table->string('send_time')->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_mode']);
        });

        Schema::rename('weekly_email_settings', 'email_settings');

        Schema::table('email_settings', function (Blueprint $table) {
            $table->json('variables')->nullable()->after('reply_to');
        });

        Schema::table('email_runs', function (Blueprint $table) {
            $table->foreignId('email_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
        });

        DB::table('email_settings')->orderBy('id')->each(function (object $settings) {
            $variables = filled($settings->zoom_link) ? [['key' => 'zoom_link', 'value' => $settings->zoom_link]] : [];

            DB::table('email_settings')->where('id', $settings->id)->update([
                'variables' => json_encode($variables),
            ]);

            $emailId = DB::table('emails')->insertGetId([
                'team_id' => $settings->team_id,
                'name' => 'Weekly email',
                'status' => $settings->enabled ? 'scheduled' : 'draft',
                'send_mode' => 'recurring',
                'subject' => $settings->subject,
                'body' => $settings->body,
                'variables' => json_encode([]),
                'send_days' => json_encode([(int) $settings->send_day]),
                'send_time' => $settings->send_time,
                'created_at' => $settings->created_at,
                'updated_at' => now(),
            ]);

            DB::table('email_runs')->where('team_id', $settings->team_id)->update(['email_id' => $emailId]);

            // Runs now hold their finished text; only {{name}} and
            // {{unsubscribe_url}} are filled in per person when sending.
            DB::table('email_runs')->where('team_id', $settings->team_id)->orderBy('id')->each(function (object $run) use ($settings) {
                DB::table('email_runs')->where('id', $run->id)->update([
                    'subject' => str_replace('{{zoom_link}}', (string) $settings->zoom_link, $run->subject),
                    'body' => str_replace('{{zoom_link}}', (string) $settings->zoom_link, $run->body),
                ]);
            });
        });

        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'subject', 'body', 'zoom_link', 'send_day', 'send_time']);
        });

        // Two emails may go out at the same moment; one email may not twice.
        // The plain index comes first: MySQL needs one on team_id for the
        // foreign key while the unique index is dropped.
        Schema::table('email_runs', function (Blueprint $table) {
            $table->index(['team_id', 'scheduled_for']);
        });

        Schema::table('email_runs', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'scheduled_for']);
            $table->unique(['email_id', 'scheduled_for']);
        });

        foreach (['team_modules', 'team_module_user'] as $table) {
            DB::table($table)->where('module_key', 'weekly-email')->update(['module_key' => 'email']);
        }

        DB::table('team_invitations')->whereNotNull('modules')->orderBy('id')->each(function (object $invitation) {
            $modules = json_decode($invitation->modules, true);

            if (is_array($modules) && in_array('weekly-email', $modules, true)) {
                $modules = array_values(array_unique(array_map(fn ($key) => $key === 'weekly-email' ? 'email' : $key, $modules)));
                DB::table('team_invitations')->where('id', $invitation->id)->update(['modules' => json_encode($modules)]);
            }
        });
    }

    public function down(): void
    {
        foreach (['team_modules', 'team_module_user'] as $table) {
            DB::table($table)->where('module_key', 'email')->update(['module_key' => 'weekly-email']);
        }

        Schema::table('email_settings', function (Blueprint $table) {
            $table->boolean('enabled')->default(false);
            $table->string('subject')->default('This week on Zoom');
            $table->text('body')->nullable();
            $table->string('zoom_link')->nullable();
            $table->unsignedTinyInteger('send_day')->default(0);
            $table->string('send_time')->default('09:00');
        });

        // Each congregation gets back its oldest recurring email.
        DB::table('email_settings')->orderBy('id')->each(function (object $settings) {
            $email = DB::table('emails')->where('team_id', $settings->team_id)->where('send_mode', 'recurring')->orderBy('id')->first();
            $zoomLink = null;

            foreach ((array) json_decode($settings->variables ?? '[]', true) as $variable) {
                if (is_array($variable) && ($variable['key'] ?? null) === 'zoom_link') {
                    $zoomLink = $variable['value'] ?? null;
                }
            }

            DB::table('email_settings')->where('id', $settings->id)->update([
                'enabled' => $email?->status === 'scheduled',
                'subject' => $email->subject ?? 'This week on Zoom',
                'body' => $email->body ?? '',
                'zoom_link' => $zoomLink,
                'send_day' => (int) (json_decode($email->send_days ?? '[0]', true)[0] ?? 0),
                'send_time' => $email->send_time ?? '09:00',
            ]);
        });

        // Runs of other emails could share a send time; keep the first of each.
        DB::table('email_runs')
            ->selectRaw('team_id, scheduled_for, MIN(id) as keep_id')
            ->groupBy('team_id', 'scheduled_for')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(fn (object $row) => DB::table('email_runs')
                ->where('team_id', $row->team_id)
                ->where('scheduled_for', $row->scheduled_for)
                ->where('id', '>', $row->keep_id)
                ->delete());

        Schema::table('email_runs', function (Blueprint $table) {
            $table->dropUnique(['email_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('email_id');
            $table->unique(['team_id', 'scheduled_for']);
        });

        Schema::table('email_runs', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'scheduled_for']);
        });

        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn('variables');
        });

        Schema::rename('email_settings', 'weekly_email_settings');
        Schema::dropIfExists('emails');
    }
};
