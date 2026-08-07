<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A club's login for a manufacturer that gates its list.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A manufacturer spec is shipped in the release and shared between clubs, so it
 * can never carry a password -- it names a profile, and the password lives
 * somewhere the spec does not reach. Until now that was only the .env, which is
 * right for a developer or a Docker secret but wrong for a club whose committee
 * member has no shell on the server. This table is the other way in: the
 * credentials a person types into the panel.
 *
 * The username and password are stored ENCRYPTED (encrypted casts on the model),
 * so a database dump -- the very backup this project takes nightly -- does not
 * hand somebody Schempp-Hirth's login in the clear. The columns are text because
 * an encrypted short string is no longer short.
 *
 * One row per profile: a source has one login, not a history of them. The unique
 * index enforces it, and an update replaces rather than appends -- a password is
 * current or it is wrong, and keeping the old one is a liability, not a record.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directive_credentials', function (Blueprint $table): void {
            $table->id();

            // The auth profile a spec names ("schempp"), not a source id -- the
            // spec is the thing that asks, and it asks by profile.
            $table->string('profile')->unique();

            // Encrypted at rest. text, because ciphertext outgrows a varchar.
            $table->text('username');
            $table->text('password');

            // Who last set it and when -- a credential change is a thing an
            // auditor may ask about, and "it just stopped working" is not an
            // answer. Not a foreign key: the user may be gone, the record stays.
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directive_credentials');
    }
};
