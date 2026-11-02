<?php
/* Ügyfélportál – e-mail-sablonok (magyar, HTML; mindegyikben egyetlen gomb a portálra)
 * Változók: name, event_date, package, link, deadline, missing (címkék listája), contact */

if (!defined('ABSPATH')) { exit; }

function cp_portal_mail_render($type, $v) {
	$name = trim((string) $v['name']);
	$hello = $name !== '' ? 'Kedves ' . $name . '!' : 'Kedves Ügyfelünk!';
	$date = (string) $v['event_date'];
	$dl = (string) $v['deadline'];
	$miss = (array) $v['missing'];
	$missHtml = $miss ? '<ul style="margin:8px 0 16px;padding-left:20px">' . implode('', array_map(function ($m) { return '<li style="margin:2px 0">' . esc_html($m) . '</li>'; }, $miss)) . '</ul>' : '';
	$p = function ($t) { return '<p style="margin:0 0 14px">' . $t . '</p>'; };

	switch ($type) {
		case 'invite':
			$subject = 'Ügyfélportál – adatok a szerződéshez · ' . $date;
			$body = $p(esc_html($hello)) .
				$p('Köszönjük, hogy minket választottatok! A rendezvényhez (' . esc_html($date) . ') megnyitottuk az ügyfélportált. Két lépésben kérünk adatokat:') .
				'<ol style="margin:0 0 14px;padding-left:20px">' .
				'<li style="margin:4px 0"><b>A szerződéshez szükséges adatok</b> (megrendelő, helyszín, időpont, szolgáltatás) – kérjük, <b>minél előbb</b>, ezekből készítjük el a szerződést.</li>' .
				'<li style="margin:4px 0"><b>A rendezvény részletei</b> (terem, bejutás, áram, helyszíni kapcsolattartó…) – ' . ($dl !== '' ? 'ráér eddig: <b>' . esc_html($dl) . '</b>.' : 'ráér a rendezvény előtti határidőig.') . '</li>' .
				'</ol>' .
				$p('Ha szeretnétek, a portálon hátteret is választhattok, és elkészíthetitek a fotósablont. A válaszok azonnal mentődnek, bármikor visszatérhettek folytatni.');
			$button = 'Ügyfélportál megnyitása';
			break;
		case 'welcome':
			$subject = 'Rendezvény-adatlap – ' . $date;
			$body = $p(esc_html($hello)) .
				$p('Köszönjük a foglalást! Hogy a rendezvényre (' . esc_html($date) . ') a kollégánk mindenre felkészülten érkezzen, kérjük, válaszolj néhány rövid kérdésre a helyszínről. Pár perc az egész, és a válaszok azonnal mentődnek – bármikor visszatérhetsz folytatni.') .
				($dl !== '' ? $p('A kitöltés határideje: <b>' . esc_html($dl) . '</b>.') : '');
			$button = 'Adatlap kitöltése';
			break;
		case 'reminder_1':
			$subject = 'Emlékeztető: rendezvény-adatlap – ' . $date;
			$body = $p(esc_html($hello)) .
				$p('Közeleg a rendezvény (' . esc_html($date) . '), de az adatlapról még ' . ($miss ? 'hiányzik ' . count($miss) . ' kötelező kérdés:' : 'hiányzik néhány válasz.')) . $missHtml .
				($dl !== '' ? $p('Kérjük, töltsd ki eddig: <b>' . esc_html($dl) . '</b>.') : '');
			$button = 'Adatlap folytatása';
			break;
		case 'reminder_2':
			$subject = 'Ma lejár az adatlap határideje – ' . $date;
			$body = $p(esc_html($hello)) .
				$p('Ma van az utolsó nap, amikor a rendezvény-adatlapot ki tudod tölteni. Még ' . ($miss ? 'ezek hiányoznak:' : 'hiányzik néhány válasz.')) . $missHtml .
				($dl !== '' ? $p('Határidő: <b>' . esc_html($dl) . '</b>. Utána az adatlap csak olvasható, a változásokat e-mailben kell jelezned.') : '');
			$button = 'Adatlap kitöltése';
			break;
		case 'completed':
			$subject = 'Megkaptuk az adatlapot – ' . $date;
			$body = $p(esc_html($hello)) .
				$p('Köszönjük, megkaptuk a rendezvény-adatlapot! A kollégánk ezek alapján készül a rendezvényre (' . esc_html($date) . ').') .
				($dl !== '' ? $p('Ha valami változik, a határidőig (' . esc_html($dl) . ') az adatlapon módosíthatod, utána írj nekünk.') : '');
			$button = 'Adatlap megtekintése';
			break;
		case 'newlink':
		case 'resend':
		default:
			$subject = 'Belépőlink a rendezvény-adatlaphoz – ' . $date;
			$body = $p(esc_html($hello)) .
				$p($type === 'newlink' ? 'Kérésedre küldjük az új belépőlinket a rendezvény-adatlapodhoz (' . esc_html($date) . ').' : 'Küldjük a belépőlinket a rendezvény-adatlapodhoz (' . esc_html($date) . ').') .
				($dl !== '' ? $p('A kitöltés határideje: <b>' . esc_html($dl) . '</b>.') : '');
			$button = 'Adatlap megnyitása';
			break;
	}
	$contact = sanitize_email((string) $v['contact']);
	$foot = 'A gomb személyes belépőlinket tartalmaz – kérjük, ne továbbítsd. Kérdésed van? ' . ($contact !== '' ? 'Válaszolj erre a levélre, vagy írj ide: ' . esc_html($contact) . '.' : 'Válaszolj erre a levélre.');
	$html = '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($subject) . '</title></head>' .
		'<body style="margin:0;padding:0;background:#F6F3EE">' .
		'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F3EE"><tr><td align="center" style="padding:24px 12px">' .
		'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden">' .
		'<tr><td style="background:#141C33;padding:18px 24px;font:800 20px Arial,Helvetica,sans-serif;color:#ffffff">Event<span style="color:#F2B35B">Crazy</span></td></tr>' .
		'<tr><td style="padding:24px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#1A1F2B">' . $body .
		'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 18px"><tr><td style="border-radius:999px;background:#E8894A">' .
		'<a href="' . esc_url($v['link']) . '" style="display:inline-block;padding:14px 26px;font:700 16px Arial,Helvetica,sans-serif;color:#1A1F2B;text-decoration:none;border-radius:999px">' . esc_html($button) . '</a></td></tr></table>' .
		'<p style="margin:0;color:#5B6275;font-size:13px">' . $foot . '</p>' .
		'</td></tr></table>' .
		'<p style="font:12px Arial,Helvetica,sans-serif;color:#8A8F9C;margin:14px 0 0">EventCrazy · <a href="https://eventcrazy.hu" style="color:#8A8F9C">eventcrazy.hu</a></p>' .
		'</td></tr></table></body></html>';
	return array('subject' => $subject, 'html' => $html);
}
