/* Crazy Booth – Windows-szkript indítása láthatatlanul (0.19.12).
 * A cserélő .cmd eddig cmd.exe-vel, „detached” módban indult: a Windows ilyenkor minden belső parancsnak (tasklist, find,
 * timeout) saját, látható fekete ablakot nyitott – a felhasználó ezt „beragadt” ablaknak látta. Most a szkriptet egy
 * kis VBScript indítja rejtett ablakkal (WScript.Shell.Run …, 0): a cmd és minden gyermeke láthatatlan konzolban fut.
 * A .vbs UTF-16 (BOM-mal), így az ékezetes útvonal („felhasználó”, „Asztali gép”) is jó. Ha a wscript nem indul,
 * a régi módon (cmd /d /s /c ""szkript" "arg" …"") indítjuk. */
const fs = require('fs'), path = require('path'), { spawn } = require('child_process');
function run(script, args, cwd) {
  const q = a => '"' + String(a).replace(/"/g, '') + '"';
  const line = '"' + [script].concat(args || []).map(q).join(' ') + '"';
  const comspec = process.env.ComSpec || 'cmd.exe';
  const old = () => spawn(comspec, ['/d', '/s', '/c', line], { cwd, detached: true, stdio: 'ignore', windowsHide: true, windowsVerbatimArguments: true }).unref();
  try {
    const vbs = script.replace(/\.cmd$/i, '') + '-rejtett.vbs';
    const cmdline = q(comspec) + ' /d /s /c ' + line;
    const src = 'CreateObject("WScript.Shell").Run "' + cmdline.replace(/"/g, '""') + '", 0, False\r\n';
    fs.writeFileSync(vbs, Buffer.concat([Buffer.from([0xFF, 0xFE]), Buffer.from(src, 'utf16le')]));
    const p = spawn(path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'wscript.exe'), ['//B', '//Nologo', vbs], { cwd, detached: true, stdio: 'ignore' });
    let failed = false; p.on('error', () => { if (!failed) { failed = true; old(); } });
    p.on('exit', c => { if (c && !failed) { failed = true; old(); } });
    p.unref();
    return 'vbs';
  } catch (e) { old(); return 'cmd'; }
}
module.exports = { run };
