#!/bin/bash

mkdir -p ssl

cat << EOF > ssl/openid-req.cnf
[req]
req_extensions = v3_req
distinguished_name = req_distinguished_name

[req_distinguished_name]

[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names

[alt_names]
DNS.1 = git.safesecs.io
DNS.2 = kubernetes
DNS.3 = kubernetes.default
DNS.4 = kubernetes.default.svc
DNS.5 = kubernetes.default.svc.cluster
DNS.6 = kubernetes.default.svc.cluster.local
EOF

openssl genrsa -out ssl/openid-ca-key.pem 2048
openssl req -x509 -new -nodes -key ssl/openid-ca-key.pem -days 10 -out ssl/openid-ca.pem -subj "/CN=system:node:kube-openid-ca"

openssl genrsa -out ssl/openid-key.pem 2048
openssl req -new -key ssl/openid-key.pem -out ssl/openid-csr.pem -subj "/CN=system:node:kube-openid-ca" -config ssl/openid-req.cnf
openssl x509 -req -in ssl/openid-csr.pem -CA ssl/openid-ca.pem -CAkey ssl/openid-ca-key.pem -CAcreateserial -out ssl/openid-cert.pem -days 10 -extensions v3_req -extfile ssl/openid-req.cnf
