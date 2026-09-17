"""Loopback-only STARTTLS SMTP sink for CI. Never forwards messages."""
import argparse, socketserver, ssl, pathlib, uuid, threading
p=argparse.ArgumentParser();p.add_argument('--port',type=int,default=25252);p.add_argument('--directory',required=True);a=p.parse_args()
root=pathlib.Path(a.directory);root.mkdir(parents=True,exist_ok=True)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(root/'cert.pem',root/'key.pem')
class SMTP(socketserver.StreamRequestHandler):
 def handle(self):
  tls=False; self.wfile.write(b'220 localhost Platzhirsch CI SMTP\r\n')
  while line:=self.rfile.readline(8192):
   cmd=line.decode('ascii',errors='replace').strip().upper()
   if cmd.startswith(('EHLO','HELO')): self.wfile.write(b'250-localhost\r\n250-STARTTLS\r\n250 AUTH PLAIN LOGIN\r\n')
   elif cmd=='STARTTLS':
    self.wfile.write(b'220 Begin TLS\r\n');self.wfile.flush()
    self.connection=context.wrap_socket(self.connection,server_side=True); self.rfile=self.connection.makefile('rb');self.wfile=self.connection.makefile('wb',buffering=0);tls=True
   elif cmd.startswith('AUTH'):
    if not tls: self.wfile.write(b'530 TLS required\r\n');continue
    if cmd.startswith('AUTH LOGIN'):
     self.wfile.write(b'334 VXNlcm5hbWU6\r\n');self.rfile.readline();self.wfile.write(b'334 UGFzc3dvcmQ6\r\n');self.rfile.readline()
    self.wfile.write(b'235 Authentication successful\r\n')
   elif cmd=='DATA':
    if not tls:self.wfile.write(b'530 TLS required\r\n');continue
    if (root/'reject').exists():self.wfile.write(b'550 Rejected for CI failure test\r\n');continue
    self.wfile.write(b'354 End with dot\r\n');data=[]
    while (part:=self.rfile.readline(1048576)) not in (b'.\r\n',b''):
     data.append(part[1:] if part.startswith(b'..') else part)
    (root/(str(uuid.uuid4())+'.eml')).write_bytes(b''.join(data));self.wfile.write(b'250 Stored locally\r\n')
   elif cmd=='QUIT':self.wfile.write(b'221 Bye\r\n');break
   else:self.wfile.write(b'250 OK\r\n')
class Server(socketserver.ThreadingTCPServer):allow_reuse_address=True;daemon_threads=True
with Server(('127.0.0.1',a.port),SMTP) as server:server.serve_forever()
