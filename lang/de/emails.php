<?php

use App\Mail\EmailTemplates;

/*
 * German wording for the client emails in App\Mail\EmailTemplates.
 * Keep every {placeholder}; ticket subjects must keep "[Ticket #{ticket_id}]",
 * which is how replies find their ticket.
 */

return [
    'invoice_created' => [
        'subject' => 'Rechnung Nr. {invoice_number} von {company_name}',
        'body' => "Hallo {client_first_name},\n\nwir haben die Rechnung Nr. {invoice_number} über {invoice_total} ausgestellt, fällig am {invoice_due_date}.\n\n[Rechnung ansehen und bezahlen]({invoice_url})\n\nVielen Dank\n{company_name}",
    ],
    'order_confirmation' => [
        'subject' => 'Bestätigung Ihrer Bestellung Nr. {order_number}',
        'body' => "Hallo {client_first_name},\n\nvielen Dank für Ihre Bestellung. Das haben Sie am {order_date} bestellt:\n\n{order_items}\n\nBestellsumme: {order_total}\n\n[Rechnung ansehen]({invoice_url})\n\nWir geben Ihnen Bescheid, sobald jeder Dienst bereit ist.\n\nVielen Dank\n{company_name}",
    ],
    'order_invoice' => [
        'subject' => 'Rechnung Nr. {invoice_number} zur Bestellung Nr. {order_number}',
        'body' => "Hallo {client_first_name},\n\nhier ist die Rechnung zu Ihrer Bestellung Nr. {order_number}: {invoice_total}, fällig am {invoice_due_date}. Ihre Dienste werden eingerichtet, sobald sie bezahlt ist.\n\n[Rechnung ansehen und bezahlen]({invoice_url})\n\nVielen Dank\n{company_name}",
    ],
    'invoice_overdue' => [
        'subject' => 'Erinnerung: Rechnung Nr. {invoice_number} ist überfällig',
        'body' => "Hallo {client_first_name},\n\ndie Rechnung Nr. {invoice_number} war am {invoice_due_date} fällig, und {invoice_balance} sind noch offen.\n\n[Rechnung bezahlen]({invoice_url})\n\nFalls Sie bereits bezahlt haben, betrachten Sie diese E-Mail bitte als gegenstandslos.\n\nVielen Dank\n{company_name}",
    ],
    'payment_receipt' => [
        'subject' => 'Zahlungsbestätigung für Rechnung Nr. {invoice_number}',
        'body' => "Hallo {client_first_name},\n\nvielen Dank für Ihre Zahlung. Die Rechnung Nr. {invoice_number} über {invoice_total} ist jetzt vollständig bezahlt.\n\n[Rechnung ansehen]({invoice_url})\n\n{company_name}",
    ],
    'service_welcome' => [
        'subject' => 'Ihr {service_name} ist bereit',
        'body' => "Hallo {client_first_name},\n\nvielen Dank für Ihre Bestellung. Ihr {service_name} ist jetzt aktiv.\n\n{product_welcome}\n\n[Dienst ansehen]({service_url})\n\nVielen Dank\n{company_name}",
    ],
    'password_reset' => [
        'subject' => 'Legen Sie Ihr Passwort für {company_name} fest',
        'body' => "Hallo {client_first_name},\n\nüber den folgenden Link können Sie das Passwort für Ihr Kundenkonto festlegen oder zurücksetzen.\n\n[Passwort festlegen]({reset_url})\n\nDieser Link läuft in {expire_minutes} Minuten ab. Falls Sie dies nicht angefordert haben, können Sie diese E-Mail ignorieren.",
    ],
    'email_verification' => [
        'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse für {company_name}',
        'body' => "Hallo {client_first_name},\n\nbitte bestätigen Sie, dass dies Ihre E-Mail-Adresse ist, damit unsere Rechnungen und Mitteilungen Sie sicher erreichen.\n\n[E-Mail-Adresse bestätigen]({verify_url})\n\nDieser Link läuft in {expire_days} Tagen ab. Falls Sie sich nicht bei {company_name} registriert haben, können Sie diese E-Mail ignorieren.",
    ],
    'contact_invite' => [
        'subject' => 'Sie haben Zugang zu {account_name} bei {company_name} erhalten',
        'body' => "Hallo {client_first_name},\n\n{account_name} hat Ihnen eine Anmeldung für das eigene Konto bei {company_name} eingerichtet. Damit können Sie: {contact_permissions}.\n\n[Passwort wählen]({reset_url})\n\nDieser Link läuft in {expire_hours} Stunden ab. Danach können Sie über „Passwort vergessen“ auf der Anmeldeseite einen neuen anfordern.",
    ],
    'contact_account_added' => [
        'subject' => 'Sie können sich jetzt bei {account_name} auf {company_name} anmelden',
        'body' => "Hallo {client_first_name},\n\n{account_name} hat Ihnen Zugang zum eigenen Konto bei {company_name} gegeben. Damit können Sie: {contact_permissions}.\n\nMelden Sie sich mit der E-Mail-Adresse und dem Passwort an, die Sie bereits verwenden. Sie werden gefragt, welches Konto Sie öffnen möchten, und können über das Menü zwischen den Konten wechseln.\n\n[Anmelden]({login_url})",
    ],
    'new_device_login' => [
        'subject' => 'Neue Anmeldung bei Ihrem Konto bei {company_name}',
        'body' => "Hallo {client_first_name},\n\nsoeben hat sich jemand von einem Gerät, das wir bisher nicht kannten, bei Ihrem Konto angemeldet:\n\n- Wann: {login_time}\n- Browser: {login_browser}\n- IP-Adresse: {login_ip}\n\nWenn Sie das waren, müssen Sie nichts tun.\n\nWenn nicht, [setzen Sie sofort Ihr Passwort zurück]({password_url}) und aktivieren Sie die Zwei-Faktor-Authentifizierung unter „Sicherheit“ im Portal.\n\n{company_name}",
    ],
    'ticket_opened' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Hallo {client_first_name},\n\nwir haben Ihre Anfrage erhalten und melden uns in Kürze. Sie können auf diese E-Mail antworten, um etwas zu ergänzen.\n\n[Ticket ansehen]({ticket_url})\n\n{company_name}",
    ],
    'ticket_reply' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Hallo {client_first_name},\n\n{staff_name} hat auf Ihr Ticket geantwortet:\n\n{reply}\n\n[Ticket ansehen]({ticket_url})\n\n{company_name}",
    ],
    'ticket_auto_closed' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Hallo {client_first_name},\n\nwir haben seit {inactive_hours} Stunden nichts von Ihnen gehört und dieses Ticket daher geschlossen. Falls Sie noch Hilfe brauchen, antworten Sie einfach auf diese E-Mail oder auf das Ticket, dann wird es wieder geöffnet.\n\n[Ticket ansehen]({ticket_url})\n\n{company_name}",
    ],
    'ticket_feedback' => [
        'subject' => 'Wie zufrieden waren Sie mit Ticket Nr. {ticket_id}?',
        'body' => "Hallo {client_first_name},\n\nIhr Ticket „{ticket_subject}“ ist jetzt geschlossen. Möchten Sie uns sagen, wie zufrieden Sie waren? Es dauert nur ein paar Sekunden und hilft uns, besser zu werden.\n\n[Unseren Support bewerten]({feedback_url})\n\nVielen Dank\n{company_name}",
    ],
];
