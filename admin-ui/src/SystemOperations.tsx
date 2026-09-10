import {useState} from 'react';
import {useQuery} from '@tanstack/react-query';
import {api} from './api';
type Item={id:string;label:string};
type Job={id:string;action:string;status:string;createdAt:string;finishedAt?:string;message:string};
type State={available:boolean;heartbeat?:string;backups:Item[];packages:Item[];jobs:Job[]};
const labels:Record<string,string>={backup:'Sicherung erstellen',verify:'Sicherung prüfen',restore:'Wiederherstellen',rollback:'Rollback',update:'Update installieren',check:'Betrieb prüfen'};
const statuses:Record<string,string>={queued:'Wartet',running:'Wird ausgeführt',success:'Erfolgreich',failed:'Fehlgeschlagen',interrupted:'Unterbrochen'};
export default function SystemOperations(){
 const q=useQuery({queryKey:['system-operations'],queryFn:()=>api<State>('v1/admin/system-operations'),refetchInterval:5000,retry:false});
 const [choice,setChoice]=useState<{action:string;target?:string;label:string;id:string}>();
 const [password,setPassword]=useState('');const [confirmed,setConfirmed]=useState(false);const [busy,setBusy]=useState(false);const [error,setError]=useState('');const [sent,setSent]=useState(false);
 const select=(action:string,target?:Item)=>{setChoice({action,target:target?.id,label:target?.label??labels[action],id:crypto.randomUUID()});setPassword('');setConfirmed(false);setError('');};
 async function submit(e:React.FormEvent){e.preventDefault();if(!choice)return;setBusy(true);setError('');try{await api('v1/admin/system-operations','POST',{action:choice.action,target:choice.target,request_id:choice.id,password,confirmation:confirmed});setSent(true);setChoice(undefined);setPassword('');await q.refetch()}catch(e){setError((e as Error).message)}finally{setBusy(false)}}
 const running=q.data?.jobs.some(j=>['queued','running'].includes(j.status));const disabled=busy||!q.data?.available||Boolean(running);
 return <section className="panel padded" style={{overflowWrap:"anywhere"}}><h2>Backups, Updates & Wiederherstellung</h2>
 <p>Sichern Sie alle eingebundenen Datenbankserver gemeinsam und installieren Sie freigegebene Anwendungspakete mit automatischer Rücknahme bei Fehlern.</p>
 {q.isPending&&<p role="status">Betriebsverwaltung wird geladen …</p>}
 {q.error&&<p role="alert">Verbindung unterbrochen. Während der Wartung kann die Anwendung nicht antworten. Die Verbindung wird automatisch erneut geprüft. Bei anhaltender Unterbrechung muss das Wartungsjournal am Server geprüft werden.</p>}
 {q.data&&!q.data.available&&<p role="alert">Die Windows-Betriebsverwaltung ist nicht erreichbar. Installieren bzw. aktivieren Sie die Verwaltung mit dem aktuellen Windows-Paket.</p>}
 {sent&&<p role="status">Auftrag übergeben. Der Status wird automatisch aktualisiert. Während der Ausführung kann die Weboberfläche vorübergehend nicht erreichbar sein.</p>}
 <div className="toolbar"><button disabled={disabled} onClick={()=>select('backup')}>Sicherung erstellen</button><button disabled={disabled} onClick={()=>select('check')}>Betrieb prüfen</button><button onClick={()=>q.refetch()} disabled={q.isFetching}>Aktualisieren</button></div>
 <h3>Sicherungen</h3>{!q.data?.backups.length&&<p>Noch keine Sicherungen verfügbar.</p>}
 {q.data?.backups.map(item=><div className="panel padded" key={item.id}><strong>{item.label}</strong><div className="toolbar">{['verify','restore','rollback'].map(action=><button key={action} disabled={disabled} onClick={()=>select(action,item)}>{labels[action]}</button>)}</div></div>)}
 <h3>Freigegebene Updates</h3><p>Hier erscheinen die vom Serveradministrator im Update-Verzeichnis bereitgestellten Pakete. Vor der Installation werden die Paketdateien geprüft.</p>
 {!q.data?.packages.length&&<p>Kein Update-Paket bereitgestellt.</p>}{q.data?.packages.map(item=><div className="toolbar" key={item.id}><span>{item.label}</span><button disabled={disabled} onClick={()=>select('update',item)}>Update installieren</button></div>)}
 {choice&&<form onSubmit={submit} className="panel padded"><h3>{labels[choice.action]}: {choice.label}</h3>
 <p>{['restore','rollback'].includes(choice.action)?'Die Daten werden auf den Sicherungszeitpunkt zurückgesetzt. Spätere Änderungen werden ersetzt; der aktuelle Stand wird vorher zusätzlich gesichert.':'Die Aktion kann die Anwendung vorübergehend in den Wartungsmodus versetzen.'}</p>
 <label>Administrator-Passwort<input type="password" autoComplete="current-password" required value={password} onChange={e=>setPassword(e.target.value)}/></label>
 <label><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/> Ich habe die Auswirkungen gelesen und möchte die Aktion ausführen.</label>
 {error&&<p role="alert">{error}</p>}<div className="toolbar"><button disabled={busy||!confirmed||!password}>Verbindlich starten</button><button type="button" disabled={busy} onClick={()=>setChoice(undefined)}>Abbrechen</button></div></form>}
 <h3>Auftragsprotokoll</h3>{q.data?.jobs.map(j=><div className="panel padded" key={j.id}><strong>{labels[j.action]??j.action} · {statuses[j.status]??j.status}</strong><p>{j.message}</p><small>{new Date(j.createdAt).toLocaleString()} · Auftrag {j.id}</small></div>)}
 </section>
}
