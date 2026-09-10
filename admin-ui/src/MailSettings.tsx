import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
type Settings = {enabled:boolean;host:string;port:number;security:string;username:string;from_address:string;from_name:string;password_set:boolean};
export default function MailSettings(){
  const q=useQuery({queryKey:['mail-settings'],queryFn:()=>api<Settings>('v1/admin/mail-settings')});
  const [draft,setDraft]=useState<Settings>();
  const [password,setPassword]=useState('');
  const [clear,setClear]=useState(false);
  const [busy,setBusy]=useState(false);
  const [message,setMessage]=useState('');
  const [error,setError]=useState('');
  const s=draft??q.data;
  if(q.error)return <p role="alert">{q.error.message}</p>;
  if(!s)return <p role="status">SMTP-Einstellungen werden geladen …</p>;
  const wpoven=s.host.toLowerCase().replace(/\.$/,'')==='smtp.freesmtpservers.com';
  const change=(key:string,value:unknown)=>{
    const next={...s,[key]:value};
    if(key==='host' && String(value).toLowerCase().replace(/\.$/,'')==='smtp.freesmtpservers.com'){next.enabled=false;next.port=25;next.security='wpoven-test';next.username='';setPassword('');}
    else if(key==='host' && s.security==='wpoven-test'){next.security='starttls';next.port=587;}
    setDraft(next);
  };
  async function save(e:React.FormEvent){e.preventDefault();setBusy(true);setError('');setMessage('');try{
    await api<Settings>('v1/admin/mail-settings','PUT',{...s,password,clear_password:clear});
    setDraft(undefined);setPassword('');setClear(false);q.refetch();setMessage('SMTP-Einstellungen gespeichert. Neue Anfragen und Hintergrundaufgaben verwenden diese Konfiguration.');
  }catch(e){setError((e as Error).message)}finally{setBusy(false)}}
  async function test(){setBusy(true);setError('');setMessage('');try{const r=await api('v1/admin/mail-settings/test','POST',{});setMessage(r.message)}catch(e){setError((e as Error).message)}finally{setBusy(false)}}
  return <section className="panel padded"><h2>E-Mail / SMTP</h2><p>Gemeinsamer Versand für Registrierung, Passwort-Zurücksetzen und Benachrichtigungen.</p>
    <p className="muted">{wpoven ? 'WPOven-Testvoreinstellung: Port 25, ohne Anmeldung. Nachrichten wären öffentlich abrufbar und werden nicht an echte Empfänger zugestellt. Registrierungs- und Passwortmails sind deshalb gesperrt. Für den Echtbetrieb einen eigenen SMTP-Server und TLS auswählen.' : 'Echter Versand über Ihren SMTP-Anbieter.'}</p>
    <form onSubmit={save} className="smtp-form">
      <label><input type="checkbox" checked={s.enabled} disabled={wpoven} onChange={e=>change('enabled',e.target.checked)}/> SMTP-Versand aktivieren</label>
      {(['host','port','username','from_address','from_name'] as const).map(key=><label key={key}>{({host:'SMTP-Server',port:'Port',username:'Benutzername',from_address:'Absenderadresse',from_name:'Absendername'})[key]}<input required={key!=='username'} type={key==='port'?'number':key==='from_address'?'email':'text'} min={key==='port'?1:undefined} max={key==='port'?65535:undefined} value={s[key]} onChange={e=>change(key,key==='port'?Number(e.target.value):e.target.value)} autoComplete="off"/></label>)}
      <label>Verschlüsselung<select value={s.security} onChange={e=>change('security',e.target.value)}>{wpoven && <option value="wpoven-test">WPOven-Testserver (Port 25, ohne TLS)</option>}<option value="starttls">STARTTLS (typisch Port 587)</option><option value="tls">TLS direkt (typisch Port 465)</option></select></label>
      <label>SMTP-Passwort<input type="password" autoComplete="new-password" value={password} onChange={e=>setPassword(e.target.value)} disabled={clear}/></label>
      <p className="muted">{s.password_set?'Ein Passwort ist gespeichert. Leer lassen, um es beizubehalten.':'Noch kein Passwort gespeichert.'}</p>
      <label><input type="checkbox" checked={clear} onChange={e=>setClear(e.target.checked)}/> Gespeichertes Passwort entfernen</label>
      <div className="toolbar"><button className="primary" disabled={busy}>Speichern</button><button type="button" disabled={busy||Boolean(draft)||Boolean(password)||clear} onClick={test}>Gespeicherte Verbindung prüfen</button></div>
    </form><p className="muted">Der Verbindungstest prüft TLS und Anmeldung. Eine Zustellprüfung erfolgt damit noch nicht.</p>{message&&<p role="status">{message}</p>}{error&&<p role="alert">{error}</p>}
  </section>
}
