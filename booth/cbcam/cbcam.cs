// Crazy Booth – cbcam: ablak nélküli DSLR-kezelő (0.19.0; 0.19.4: üzenetkezelős kameraszál – Canon).
// A digiCamControl kamerakezelő könyvtárát (CameraControl.Devices, MIT, Duka István) használja, a grafikus program nélkül.
// Ugyanazt a HTTP-felületet adja a 127.0.0.1-en, mint a digiCamControl webszervere – a Crazy Booth camera/dcc.js változatlanul hívja:
//   /                                   → "OK" (él-e)
//   /?slc=set&param1=session.folder&param2=<mappa>
//   /?CMD=LiveViewWnd_Show | LiveViewWnd_Hide
//   /liveview.jpg                       → az utolsó élőkép-képkocka (JPG)
//   /?slc=list|get|set&param1=<iso|aperture|shutterspeed|exposurecompensation|whitebalance|camera.temperature>&param2=<érték>
//   /?slc=capture                       → exponálás; /?slc=get&param1=lastcaptured → "-" amíg tart, utána a kész fájl útvonala
//   /image/<név>                        → a mentett kép
// Indítás: cbcam.exe <port> <szülő PID> <naplómappa>. A szülő (Crazy Booth) kilépésekor leáll.
// C# 5 (a Windows beépített csc.exe-jével fordul), 32 bites (a digiCamControl könyvtárai x86-osak).
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Windows.Threading;
using CameraControl.Devices;
using CameraControl.Devices.Classes;

class CbCam
{
    static CameraDeviceManager dm;
    static readonly object camLock = new object();
    // 0.19.4: minden kameraművelet egy saját STA-szálon, üzenetkezeléssel (Dispatcher) fut – a Canon EDSDK (és a WIA)
    // a „kép elkészült” eseményt Windows-üzenetként küldi; üzenetkezelés nélkül az exponálás után nem jött át a kép
    static Dispatcher camD;
    static T OnCam<T>(Func<T> f) { return camD.CheckAccess() ? f() : camD.Invoke(f); }
    static void OnCam(Action a) { if (camD.CheckAccess()) a(); else camD.Invoke(a); }
    static string folder = Path.Combine(Path.GetTempPath(), "cbcam");
    static string lastCaptured = "";
    static volatile bool capturing = false;
    static volatile bool lvWanted = false;
    static volatile bool lvOn = false;
    static byte[] lastFrame = null;
    static DateTime lastFrameAt = DateTime.MinValue;
    static string logFile = null;
    static DateTime lastConnectTry = DateTime.MinValue;

    static void Log(string m)
    {
        try
        {
            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + m;
            Console.WriteLine(line);
            if (logFile != null) File.AppendAllText(logFile, line + "\r\n");
        }
        catch { }
    }

    static int Main(string[] args)
    {
        int port = args.Length > 0 ? int.Parse(args[0]) : 5513;
        int parent = args.Length > 1 ? int.Parse(args[1]) : 0;
        if (args.Length > 2) { try { Directory.CreateDirectory(args[2]); logFile = Path.Combine(args[2], "cbcam.log"); if (File.Exists(logFile) && new FileInfo(logFile).Length > 2000000) File.Delete(logFile); } catch { } }
        Log("cbcam indul (port " + port + ")");
        if (parent > 0)
        {
            new Thread(() =>
            {
                while (true)
                {
                    Thread.Sleep(2000);
                    try { Process.GetProcessById(parent); }
                    catch { Log("a Crazy Booth kilépett – cbcam leáll"); Shutdown(); Environment.Exit(0); }
                }
            }) { IsBackground = true }.Start();
        }
        ManualResetEvent ready = new ManualResetEvent(false);
        Thread ct = new Thread(() => { camD = Dispatcher.CurrentDispatcher; try { Connect(); } catch (Exception e) { Log("indulás: " + e.Message); } ready.Set(); Dispatcher.Run(); });
        ct.SetApartmentState(ApartmentState.STA); ct.IsBackground = true; ct.Start();
        ready.WaitOne();
        new Thread(LiveLoop) { IsBackground = true }.Start();
        TcpListener srv = new TcpListener(IPAddress.Loopback, port);
        try { srv.Start(); }
        catch (Exception e) { Log("a port foglalt (" + port + "): " + e.Message); return 2; }
        Log("HTTP kész: 127.0.0.1:" + port);
        while (true)
        {
            TcpClient c = srv.AcceptTcpClient();
            ThreadPool.QueueUserWorkItem(o => Handle((TcpClient)o), c);
        }
    }

    static void Shutdown()
    {
        try { OnCam(() => { if (Cam() != null && lvOn) Cam().StopLiveView(); if (dm != null) dm.CloseAll(); }); } catch { }
    }

    static ICameraDevice Cam()
    {
        if (dm == null) return null;
        ICameraDevice d = dm.SelectedCameraDevice;
        if (d == null || !d.IsConnected || d.GetType().Name == "NotConnectedCameraDevice") return null;
        return d;
    }

    static void Connect()
    {
        lastConnectTry = DateTime.Now;
        try
        {
            if (dm == null)
            {
                dm = new CameraDeviceManager();
                dm.DetectWebcams = false;
                dm.PhotoCaptured += OnPhoto;
                dm.CameraConnected += d => Log("kamera csatlakozott: " + d.DeviceName);
                dm.CameraDisconnected += d => { Log("kamera lecsatlakozott: " + d.DeviceName); lvOn = false; };
            }
            dm.ConnectToCamera();
            ICameraDevice d0 = Cam();
            Log(d0 != null ? "kamera: " + d0.DeviceName : "nincs csatlakoztatott kamera");
        }
        catch (Exception e) { Log("csatlakozási hiba: " + e.Message); }
    }

    static ICameraDevice NeedCam()
    {
        ICameraDevice d = Cam();
        if (d == null && (DateTime.Now - lastConnectTry).TotalSeconds > 4) { OnCam(Connect); d = Cam(); }
        if (d == null) throw new Exception("Nincs csatlakoztatott fényképező (be van kapcsolva, USB-n csatlakozik?)");
        return d;
    }

    // élőkép: külön szálon, ~12 kép/mp; exponálás alatt szünetel
    static void LiveLoop()
    {
        while (true)
        {
            try
            {
                if (!lvWanted || capturing) { Thread.Sleep(60); continue; }
                ICameraDevice d = Cam();
                if (d == null) { Thread.Sleep(500); continue; }
                if (!lvOn) { OnCam(() => d.StartLiveView()); lvOn = true; Thread.Sleep(300); }
                LiveViewData lv = OnCam(() => d.GetLiveViewImage());
                if (lv != null && lv.ImageData != null && lv.ImageData.Length > lv.ImageDataPosition)
                {
                    byte[] b = new byte[lv.ImageData.Length - lv.ImageDataPosition];
                    Buffer.BlockCopy(lv.ImageData, lv.ImageDataPosition, b, 0, b.Length);
                    lastFrame = b; lastFrameAt = DateTime.Now;
                }
                Thread.Sleep(80);
            }
            catch (Exception e) { Log("élőkép: " + e.Message); Thread.Sleep(400); }
        }
    }

    static void LiveStop()
    {
        try { OnCam(() => { ICameraDevice d = Cam(); if (d != null && lvOn) d.StopLiveView(); }); } catch (Exception e) { Log("élőkép leállítása: " + e.Message); }
        lvOn = false;
    }

    static void Capture()
    {
        ICameraDevice d = NeedCam();
        if (capturing) throw new Exception("Az előző exponálás még tart.");
        capturing = true; lastCaptured = "-";
        new Thread(() =>
        {
            try
            {
                bool wasLv = lvOn;
                if (wasLv) { LiveStop(); Thread.Sleep(250); }
                OnCam(() =>
                {
                    d.IsBusy = true;
                    try { d.CaptureInSdRam = d.GetCapability(CapabilityEnum.CaptureInRam); } catch { }
                    d.CapturePhoto();
                });
                Log("exponálás elindítva");
            }
            catch (Exception e)
            {
                Log("exponálási hiba: " + e.Message);
                d.IsBusy = false; capturing = false; lastCaptured = "HIBA: " + e.Message;
            }
        }) { IsBackground = true }.Start();
        // biztonsági háló: ha 25 mp alatt nem jön kép, felszabadítjuk
        new Thread(() => { Thread.Sleep(25000); if (capturing) { capturing = false; if (lastCaptured == "-") lastCaptured = "HIBA: nem jött kép"; Log("exponálás: időtúllépés"); } }) { IsBackground = true }.Start();
    }

    static void OnPhoto(object sender, PhotoCapturedEventArgs e)
    {
        Log("kép elkészült a kamerán: " + (e.FileName ?? "?"));
        camD.BeginInvoke(new Action(() =>
        {
            try
            {
                Directory.CreateDirectory(folder);
                string ext = Path.GetExtension(e.FileName ?? "");
                if (string.IsNullOrEmpty(ext)) ext = ".jpg";
                string name = "cb_" + DateTime.Now.ToString("yyyyMMdd_HHmmss_fff") + ext.ToLowerInvariant();
                string full = Path.Combine(folder, name);
                e.CameraDevice.TransferFile(e.Handle, full);
                e.CameraDevice.IsBusy = false;
                Log("kép mentve: " + full);
                // RAW+JPG esetén a JPG számít; a RAW-ot nem jelezzük vissza
                if (!ext.Equals(".nef", StringComparison.OrdinalIgnoreCase) && !ext.Equals(".cr2", StringComparison.OrdinalIgnoreCase) && !ext.Equals(".cr3", StringComparison.OrdinalIgnoreCase))
                    lastCaptured = full;
            }
            catch (Exception ex) { Log("képátvitel hiba: " + ex.Message); lastCaptured = "HIBA: " + ex.Message; try { e.CameraDevice.IsBusy = false; } catch { } }
            finally { capturing = false; }
        }));
    }

    static PropertyValue<long> Prop(ICameraDevice d, string p)
    {
        switch ((p ?? "").ToLowerInvariant())
        {
            case "iso": case "camera.isonumber": return d.IsoNumber;
            case "aperture": case "camera.fnumber": return d.FNumber;
            case "shutterspeed": case "camera.shutterspeed": return d.ShutterSpeed;
            case "exposurecompensation": case "camera.exposurecompensation": return d.ExposureCompensation;
            case "whitebalance": case "camera.whitebalance": return d.WhiteBalance;
            case "temperature": case "camera.temperature":
                if (d.AdvancedProperties != null)
                    foreach (PropertyValue<long> a in d.AdvancedProperties)
                        if ((a.Name ?? "").ToLowerInvariant().Contains("temperature")) return a;
                return null;
        }
        return null;
    }

    static string Esc(string s) { return "\"" + (s ?? "").Replace("\\", "\\\\").Replace("\"", "\\\"") + "\""; }

    static void Handle(TcpClient c)
    {
        try
        {
            c.ReceiveTimeout = 5000;
            using (NetworkStream ns = c.GetStream())
            {
                StreamReader rd = new StreamReader(ns, Encoding.ASCII);
                string first = rd.ReadLine();
                if (first == null) return;
                string h; while (!string.IsNullOrEmpty(h = rd.ReadLine())) { }
                string[] parts = first.Split(' ');
                string url = parts.Length > 1 ? parts[1] : "/";
                int code = 200; string type = "text/plain; charset=utf-8"; byte[] body;
                try { body = Route(url, ref type); }
                catch (Exception e) { code = 500; body = Encoding.UTF8.GetBytes(e.Message); type = "text/plain; charset=utf-8"; }
                if (body == null) { code = 404; body = Encoding.UTF8.GetBytes("nincs"); }
                string head = "HTTP/1.1 " + code + (code == 200 ? " OK" : code == 404 ? " Not Found" : " Error") + "\r\nContent-Type: " + type + "\r\nContent-Length: " + body.Length + "\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
                byte[] hb = Encoding.ASCII.GetBytes(head);
                ns.Write(hb, 0, hb.Length); ns.Write(body, 0, body.Length); ns.Flush();
            }
        }
        catch (Exception e) { Log("kérés: " + e.Message); }
        finally { try { c.Close(); } catch { } }
    }

    static Dictionary<string, string> Query(string url)
    {
        Dictionary<string, string> q = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        int i = url.IndexOf('?'); if (i < 0) return q;
        foreach (string kv in url.Substring(i + 1).Split('&'))
        {
            int e = kv.IndexOf('='); if (e < 0) { q[Uri.UnescapeDataString(kv)] = ""; continue; }
            q[Uri.UnescapeDataString(kv.Substring(0, e))] = Uri.UnescapeDataString(kv.Substring(e + 1).Replace('+', ' '));
        }
        return q;
    }

    static byte[] T(string s) { return Encoding.UTF8.GetBytes(s); }

    static byte[] Route(string url, ref string type)
    {
        string path = url.Split('?')[0];
        Dictionary<string, string> q = Query(url);
        if (path.StartsWith("/liveview.jpg"))
        {
            if (!lvWanted) lvWanted = true;
            byte[] f = lastFrame;
            if (f == null) throw new Exception("még nincs élőkép");
            type = "image/jpeg"; return f;
        }
        if (path.StartsWith("/image/"))
        {
            string n = Path.GetFileName(Uri.UnescapeDataString(path.Substring(7)));
            string f = Path.Combine(folder, n);
            if (!File.Exists(f)) return null;
            type = "image/jpeg"; return File.ReadAllBytes(f);
        }
        if (q.ContainsKey("CMD"))
        {
            string cmd = q["CMD"];
            if (cmd == "LiveViewWnd_Show") { NeedCam(); lvWanted = true; return T("OK"); }
            if (cmd == "LiveViewWnd_Hide") { lvWanted = false; LiveStop(); return T("OK"); }
            return T("OK");
        }
        if (q.ContainsKey("slc"))
        {
            string slc = q["slc"].ToLowerInvariant();
            string p1 = q.ContainsKey("param1") ? q["param1"] : "", p2 = q.ContainsKey("param2") ? q["param2"] : "";
            if (slc == "capture") { Capture(); return T("OK"); }
            if (slc == "get" && p1.Equals("lastcaptured", StringComparison.OrdinalIgnoreCase)) return T(lastCaptured);
            if (slc == "set" && p1.Equals("session.folder", StringComparison.OrdinalIgnoreCase)) { if (p2 != "") { folder = p2; Directory.CreateDirectory(folder); } return T("OK"); }
            ICameraDevice d = NeedCam();
            PropertyValue<long> pv = OnCam(() => Prop(d, p1));
            if (pv == null) return T(slc == "list" ? "[]" : "");
            if (slc == "list") { type = "application/json; charset=utf-8"; string[] vals = OnCam(() => pv.Values.ToArray()); return T("[" + string.Join(",", vals.Select(Esc).ToArray()) + "]"); }
            if (slc == "get") return T(OnCam(() => pv.Value) ?? "");
            if (slc == "set")
            {
                OnCam(() => pv.SetValue(p2));
                return T("OK");
            }
        }
        if (path == "/" || path == "") { var c0 = Cam(); string nm = ""; try { nm = c0 == null ? "" : (c0.DeviceName ?? ""); } catch { } return T("OK" + (c0 == null ? " (nincs kamera)" : (nm != "" ? " | " + nm : ""))); }
        return null;
    }
}
