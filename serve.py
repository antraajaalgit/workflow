import os, http.server, socketserver
os.chdir(os.path.dirname(os.path.abspath(__file__)))
socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(("", 8777), http.server.SimpleHTTPRequestHandler) as httpd:
    httpd.serve_forever()
