<?php

use App\Mail\EmailTemplates;

/*
 * French wording for the client emails in App\Mail\EmailTemplates.
 * Keep every {placeholder}; ticket subjects must keep "[Ticket #{ticket_id}]",
 * which is how replies find their ticket.
 */

return [
    'invoice_created' => [
        'subject' => 'Facture n° {invoice_number} de {company_name}',
        'body' => "Bonjour {client_first_name},\n\nNous avons émis la facture n° {invoice_number} d’un montant de {invoice_total}, à régler avant le {invoice_due_date}.\n\n[Consulter et payer la facture]({invoice_url})\n\nMerci,\n{company_name}",
    ],
    'quote_delivered' => [
        'subject' => 'Devis n° {quote_number} de {company_name} : {quote_subject}',
        'body' => "Bonjour {client_first_name},\n\nVoici notre devis n° {quote_number} pour {quote_subject} : {quote_total}, valable jusqu’au {quote_valid_until}. Le PDF est joint à cet e-mail.\n\n[Consulter et accepter le devis]({quote_url})\n\nPour toute question, il suffit de répondre à cet e-mail.\n\nMerci,\n{company_name}",
    ],
    'order_confirmation' => [
        'subject' => 'Confirmation de la commande n° {order_number}',
        'body' => "Bonjour {client_first_name},\n\nMerci pour votre commande. Voici ce que vous avez commandé le {order_date} :\n\n{order_items}\n\nTotal de la commande : {order_total}\n\n[Consulter la facture]({invoice_url})\n\nNous vous préviendrons dès que chaque service sera prêt.\n\nMerci,\n{company_name}",
    ],
    'order_invoice' => [
        'subject' => 'Facture n° {invoice_number} pour la commande n° {order_number}',
        'body' => "Bonjour {client_first_name},\n\nVoici la facture de la commande n° {order_number} : {invoice_total}, à régler avant le {invoice_due_date}. Vos services seront mis en place dès son paiement.\n\n[Consulter et payer la facture]({invoice_url})\n\nMerci,\n{company_name}",
    ],
    'invoice_overdue' => [
        'subject' => 'Rappel : la facture n° {invoice_number} est en retard',
        'body' => "Bonjour {client_first_name},\n\nLa facture n° {invoice_number} était à régler avant le {invoice_due_date} et {invoice_balance} restent impayés.\n\n[Payer la facture]({invoice_url})\n\nSi vous avez déjà payé, veuillez ne pas tenir compte de cet e-mail.\n\nMerci,\n{company_name}",
    ],
    'payment_receipt' => [
        'subject' => 'Reçu pour la facture n° {invoice_number}',
        'body' => "Bonjour {client_first_name},\n\nMerci pour votre paiement. La facture n° {invoice_number} d’un montant de {invoice_total} est désormais entièrement réglée.\n\n[Consulter la facture]({invoice_url})\n\n{company_name}",
    ],
    'payment_failed' => [
        'subject' => 'Échec du paiement de la facture n° {invoice_number}',
        'body' => "Bonjour {client_first_name},\n\nNous avons tenté de débiter {invoice_balance} pour la facture n° {invoice_number} sur votre {payment_method}, mais le paiement n’a pas abouti : {failure_reason}\n\n[Payer la facture]({invoice_url})\n\nPour utiliser une autre carte lors des prochains paiements, [mettez à jour vos moyens de paiement]({payment_methods_url}).\n\nMerci,\n{company_name}",
    ],
    'service_welcome' => [
        'subject' => 'Votre {service_name} est prêt',
        'body' => "Bonjour {client_first_name},\n\nMerci pour votre commande. Votre {service_name} est maintenant actif.\n\n{product_welcome}\n\n[Consulter votre service]({service_url})\n\nMerci,\n{company_name}",
    ],
    'password_reset' => [
        'subject' => 'Définissez votre mot de passe {company_name}',
        'body' => "Bonjour {client_first_name},\n\nUtilisez le lien ci-dessous pour définir ou réinitialiser le mot de passe de votre compte client.\n\n[Définir le mot de passe]({reset_url})\n\nCe lien expire dans {expire_minutes} minutes. Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.",
    ],
    'email_verification' => [
        'subject' => 'Confirmez votre adresse e-mail pour {company_name}',
        'body' => "Bonjour {client_first_name},\n\nVeuillez confirmer qu’il s’agit bien de votre adresse e-mail, afin que nos factures et nos informations vous parviennent.\n\n[Confirmer mon adresse e-mail]({verify_url})\n\nCe lien expire dans {expire_days} jours. Si vous ne vous êtes pas inscrit chez {company_name}, vous pouvez ignorer cet e-mail.",
    ],
    'contact_invite' => [
        'subject' => 'Vous avez désormais accès à {account_name} chez {company_name}',
        'body' => "Bonjour {client_first_name},\n\n{account_name} vous a créé un accès à son compte chez {company_name}. Vous pouvez ainsi : {contact_permissions}.\n\n[Choisir votre mot de passe]({reset_url})\n\nCe lien expire dans {expire_hours} heures. Passé ce délai, utilisez « Mot de passe oublié » sur la page de connexion pour en obtenir un nouveau.",
    ],
    'contact_account_added' => [
        'subject' => 'Vous pouvez maintenant vous connecter à {account_name} chez {company_name}',
        'body' => "Bonjour {client_first_name},\n\n{account_name} vous a donné accès à son compte chez {company_name}. Vous pouvez ainsi : {contact_permissions}.\n\nConnectez-vous avec l’adresse e-mail et le mot de passe que vous utilisez déjà. Il vous sera demandé quel compte ouvrir, et vous pourrez passer d’un compte à l’autre depuis le menu.\n\n[Se connecter]({login_url})",
    ],
    'new_device_login' => [
        'subject' => 'Nouvelle connexion à votre compte {company_name}',
        'body' => "Bonjour {client_first_name},\n\nQuelqu’un vient de se connecter à votre compte depuis un appareil que nous ne connaissions pas :\n\n- Quand : {login_time}\n- Navigateur : {login_browser}\n- Adresse IP : {login_ip}\n\nSi c’était vous, vous n’avez rien à faire.\n\nSinon, [réinitialisez votre mot de passe]({password_url}) sans attendre et activez l’authentification à deux facteurs dans la rubrique Sécurité du portail.\n\n{company_name}",
    ],
    'ticket_opened' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Bonjour {client_first_name},\n\nNous avons bien reçu votre demande et vous répondrons rapidement. Vous pouvez répondre à cet e-mail pour la compléter.\n\n[Consulter le ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_reply' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Bonjour {client_first_name},\n\n{staff_name} a répondu à votre ticket :\n\n{reply}\n\n[Consulter le ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_auto_closed' => [
        'subject' => EmailTemplates::TICKET_SUBJECT,
        'body' => "Bonjour {client_first_name},\n\nSans nouvelles de votre part depuis {inactive_hours} heures, nous avons fermé ce ticket. Si vous avez encore besoin d’aide, répondez simplement à cet e-mail ou au ticket, et il sera rouvert.\n\n[Consulter le ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_feedback' => [
        'subject' => 'Qu’avez-vous pensé de notre aide pour le ticket n° {ticket_id} ?',
        'body' => "Bonjour {client_first_name},\n\nVotre ticket « {ticket_subject} » est maintenant fermé. Pourriez-vous nous dire ce que vous avez pensé de notre aide ? Cela ne prend que quelques secondes et nous aide à nous améliorer.\n\n[Évaluer notre support]({feedback_url})\n\nMerci,\n{company_name}",
    ],
    'domain_registered' => [
        'subject' => '{domain} est enregistré',
        'body' => "Bonjour {client_first_name},\n\nBonne nouvelle : {domain} est maintenant enregistré à votre nom jusqu'au {expiry_date}.\n\nVous pouvez modifier ses serveurs de noms, ses coordonnées et plus encore dans le portail.\n\n[Gérer {domain}]({domain_url})\n\nMerci,\n{company_name}",
    ],
    'domain_transferred' => [
        'subject' => '{domain} est maintenant chez nous',
        'body' => "Bonjour {client_first_name},\n\nLe transfert de {domain} est terminé. Il se renouvelle désormais chez nous et expire le {expiry_date}.\n\n[Gérer {domain}]({domain_url})\n\nMerci,\n{company_name}",
    ],
    'domain_renewed' => [
        'subject' => '{domain} est renouvelé jusqu\'au {expiry_date}',
        'body' => "Bonjour {client_first_name},\n\n{domain} est renouvelé et expire maintenant le {expiry_date}.\n\n[Gérer {domain}]({domain_url})\n\nMerci,\n{company_name}",
    ],
    'domain_renewal_reminder' => [
        'subject' => '{domain} expire dans {days_left} jours',
        'body' => "Bonjour {client_first_name},\n\n{domain} expire le {expiry_date}. {renewal_note}\n\nLe renouveler pour {renewal_years} an(s) coûte {renewal_amount}.\n\n[Renouveler {domain}]({domain_url})\n\nMerci,\n{company_name}",
    ],
    'domain_expired' => [
        'subject' => '{domain} a expiré',
        'body' => "Bonjour {client_first_name},\n\n{domain} a expiré le {expiry_date}. Les sites web et les e-mails qui l'utilisent peuvent cesser de fonctionner.\n\nLa plupart des domaines peuvent encore être renouvelés peu de temps après leur expiration : renouvelez-le maintenant si vous souhaitez le garder.\n\n[Renouveler {domain}]({domain_url})\n\nMerci,\n{company_name}",
    ],
];
