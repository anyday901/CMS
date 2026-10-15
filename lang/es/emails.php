<?php

/*
 * Spanish wording for the client emails in App\Mail\EmailTemplates.
 * Keep every {placeholder}; ticket subjects must keep "[Ticket #{ticket_id}]",
 * which is how replies find their ticket.
 */

return [
    'invoice_created' => [
        'subject' => 'Factura n.º {invoice_number} de {company_name}',
        'body' => "Hola, {client_first_name}:\n\nHemos emitido la factura n.º {invoice_number} por {invoice_total}, con vencimiento el {invoice_due_date}.\n\n[Ver y pagar la factura]({invoice_url})\n\nGracias,\n{company_name}",
    ],
    'order_confirmation' => [
        'subject' => 'Confirmación del pedido n.º {order_number}',
        'body' => "Hola, {client_first_name}:\n\nGracias por su pedido. Esto es lo que pidió el {order_date}:\n\n{order_items}\n\nTotal del pedido: {order_total}\n\n[Ver la factura]({invoice_url})\n\nLe avisaremos cuando cada servicio esté listo.\n\nGracias,\n{company_name}",
    ],
    'order_invoice' => [
        'subject' => 'Factura n.º {invoice_number} del pedido n.º {order_number}',
        'body' => "Hola, {client_first_name}:\n\nAquí tiene la factura del pedido n.º {order_number}: {invoice_total}, con vencimiento el {invoice_due_date}. Sus servicios se configurarán en cuanto esté pagada.\n\n[Ver y pagar la factura]({invoice_url})\n\nGracias,\n{company_name}",
    ],
    'invoice_overdue' => [
        'subject' => 'Recordatorio: la factura n.º {invoice_number} está vencida',
        'body' => "Hola, {client_first_name}:\n\nLa factura n.º {invoice_number} vencía el {invoice_due_date} y aún quedan {invoice_balance} pendientes de pago.\n\n[Pagar la factura]({invoice_url})\n\nSi ya la ha pagado, ignore este correo.\n\nGracias,\n{company_name}",
    ],
    'payment_receipt' => [
        'subject' => 'Recibo de la factura n.º {invoice_number}',
        'body' => "Hola, {client_first_name}:\n\nGracias por su pago. La factura n.º {invoice_number} por {invoice_total} ya está pagada en su totalidad.\n\n[Ver la factura]({invoice_url})\n\n{company_name}",
    ],
    'service_welcome' => [
        'subject' => 'Su {service_name} está listo',
        'body' => "Hola, {client_first_name}:\n\nGracias por su pedido. Su {service_name} ya está activo.\n\n{product_welcome}\n\n[Ver su servicio]({service_url})\n\nGracias,\n{company_name}",
    ],
    'password_reset' => [
        'subject' => 'Establezca su contraseña de {company_name}',
        'body' => "Hola, {client_first_name}:\n\nUse el siguiente enlace para establecer o restablecer la contraseña de su cuenta de cliente.\n\n[Establecer contraseña]({reset_url})\n\nEste enlace caduca en {expire_minutes} minutos. Si no lo ha solicitado, puede ignorar este correo.",
    ],
    'email_verification' => [
        'subject' => 'Confirme su dirección de correo electrónico para {company_name}',
        'body' => "Hola, {client_first_name}:\n\nConfirme que esta es su dirección de correo electrónico, para que nuestras facturas y avisos le lleguen.\n\n[Confirmar mi correo]({verify_url})\n\nEste enlace caduca en {expire_days} días. Si no se ha registrado en {company_name}, puede ignorar este correo.",
    ],
    'contact_invite' => [
        'subject' => 'Ya tiene acceso a {account_name} en {company_name}',
        'body' => "Hola, {client_first_name}:\n\n{account_name} le ha dado un acceso a su cuenta en {company_name}. Con él puede: {contact_permissions}.\n\n[Elegir su contraseña]({reset_url})\n\nEste enlace caduca en {expire_hours} horas. Después, use «¿Ha olvidado su contraseña?» en la página de inicio de sesión para obtener uno nuevo.",
    ],
    'contact_account_added' => [
        'subject' => 'Ya puede iniciar sesión en {account_name} en {company_name}',
        'body' => "Hola, {client_first_name}:\n\n{account_name} le ha dado acceso a su cuenta en {company_name}. Con él puede: {contact_permissions}.\n\nInicie sesión con el correo y la contraseña que ya usa. Se le preguntará qué cuenta abrir, y podrá cambiar de cuenta desde el menú.\n\n[Iniciar sesión]({login_url})",
    ],
    'new_device_login' => [
        'subject' => 'Nuevo inicio de sesión en su cuenta de {company_name}',
        'body' => "Hola, {client_first_name}:\n\nAlguien acaba de iniciar sesión en su cuenta desde un dispositivo que no habíamos visto antes:\n\n- Cuándo: {login_time}\n- Navegador: {login_browser}\n- Dirección IP: {login_ip}\n\nSi ha sido usted, no tiene que hacer nada.\n\nSi no, [restablezca su contraseña]({password_url}) de inmediato y active la verificación en dos pasos en Seguridad, dentro del portal.\n\n{company_name}",
    ],
    'ticket_opened' => [
        'subject' => '[Ticket #{ticket_id}] {ticket_subject}',
        'body' => "Hola, {client_first_name}:\n\nHemos recibido su solicitud y le responderemos pronto. Puede responder a este correo para añadir más información.\n\n[Ver el ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_reply' => [
        'subject' => '[Ticket #{ticket_id}] {ticket_subject}',
        'body' => "Hola, {client_first_name}:\n\n{staff_name} ha respondido a su ticket:\n\n{reply}\n\n[Ver el ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_auto_closed' => [
        'subject' => '[Ticket #{ticket_id}] {ticket_subject}',
        'body' => "Hola, {client_first_name}:\n\nNo hemos tenido noticias suyas en {inactive_hours} horas, así que hemos cerrado este ticket. Si todavía necesita ayuda, responda a este correo o al ticket y se volverá a abrir.\n\n[Ver el ticket]({ticket_url})\n\n{company_name}",
    ],
    'ticket_feedback' => [
        'subject' => '¿Qué tal lo hicimos con el ticket n.º {ticket_id}?',
        'body' => "Hola, {client_first_name}:\n\nSu ticket «{ticket_subject}» ya está cerrado. ¿Podría decirnos qué tal lo hicimos? Solo le llevará unos segundos y nos ayuda a mejorar.\n\n[Valorar nuestro soporte]({feedback_url})\n\nGracias,\n{company_name}",
    ],
];
