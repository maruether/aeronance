<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Dokument darf ein VERWEIS sein statt einer Datei.
 *
 * Der Anlass ist die Freigabebescheinigung (CRS): Sie lebt in der Werkstatt
 * und wird dort gedruckt; die Lebenslaufakte soll auf sie ZEIGEN, nicht eine
 * zweite Abschrift fuehren, die von der ersten abweichen koennte. Die URL
 * kommt fertig ueber die Ereignis-Nutzlast (ReleaseIssued) -- die Flotte
 * kennt keinen fremden Routennamen, sie legt nur ab, was ihr gemeldet wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table): void {
            $table->string('link', 500)->nullable()->after('issued_by');
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table): void {
            $table->dropColumn('link');
        });
    }
};
