### Lessons Learned

**Enable Dynamic Volume Provisioning**
###

Have to add --cloud-provider=gce to your /etc/systemd/system/kubelet.service for k8s to mount volumes for you dynamically. Doing this changes your hostname to gcloud's hostname; because of this, you need to create new certs in order to use `kubectl exec` on pods.

Generate New Certs (worker.cnf) *Allows weird hostnaming of gce*
#### ###
```
[req]
req_extensions = v3_req
distinguished_name = req_distinguished_name
[req_distinguished_name]
[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names
[alt_names]
DNS.1 = kubernetes
DNS.2 = kubernetes.default
DNS.3 = *.*.*.mastodon-pos.internal
DNS.4 = *.*.c.mastodon-pos.internal
DNS.5 = *.us-west1-c.c.mastodon-pos.internal
```

```
 { for instance in worker-0 worker-1 worker-2; do openssl req -new -key ${instance}-key.pem -out ${instance}.csr -subj "/cn=system:node:${instance}" -config worker.cnf; openssl x509 -req -in ${instance}.csr -CA /ca.pem  -CAkey /ca-key.pem -CAcreateserial -out ${instance}.pem -days 365 -extensions v3_req -extfile worker.cnf; done; }
```

```
{ for instance in worker-0 worker-1 worker-2; do gcloud compute scp ${instance}.pem ${instance}:~/; done; }
```
Now overwrite the old certs in /var/lib/kubelet/ with the new certs and restart kubelet and kube-proxy service.

```
cp ~/worker-0.pem /var/lib/kubelet/worker-0.pem
sudo systemctl daemon-reload; sudo sytemctl restart kubelet kube-proxy
journalctl -xe
```

Now add --cloud-provider=gce and --cloud-config=/etc/kubernetes/cloud-config to /etc/systemd/system/kube-apiserver.service and /etc/systemd/system/kube-controller-manager.service to enable dynamic volume provisioning.

/etc/kubernetes/cloud-config Contents
####         
```
[Global]
project_id = "Project id"
local-zone = "i.e. us-east1-b"
```
---
Create mysql statefulset
#
After using neccessary files from our gitea server:
**Ensure env var, MYSQL_ALLOW_EMPTY_PASSWORD, inside of stateful set is set to 1**

```
kubectl exec -it mysql-0 mysql -- mysql -u root
create user 'root'@'%.us-west1-c.c.mastolab-pos.internal' identified by '';
grant all privileges on *.* to 'root'@'%.us-west1-c.c.mastolab-pos.internal';
flush privileges;
```
This allows any internal mastond-pos node to access the mysql database. *One could use a less limiting host to support multiple regions*

---
Alpine images have an issue on Kubernetes with external DNS queries. Echo 'nameserver 8.8.8.8' and remove other entries inside of /etc/resolv.conf before trying to use external addresses.

## Using NFS inside of gcp-disks ##
If using kubernetes to host an nfs server you need to install nfs-common on all worker nodes to be able to use the nfs disk in other deployments.

## Creating WildCard DNS ##
Use acmedns to validate wildcard dns. Follow [this guide](https://medium.com/google-cloud/kubernetes-w-lets-encrypt-cloud-dns-c888b2ff8c0e) ignore anything before the cert-manager portion. You will have to create a gcloud service account with dns permissions. After that, setup namecheap to use google cloud dns and setup google cloud dns to point to your ip and create a wildcard ('\*.safesecs.io') resolver in cloud dns. Create a staging and production dns cluster issuer inside of k8s; also, link your service account in kubernetes with the one in google. Then deploy a wildcard certificate with yaml and you should have it. Create the ingress and you're all setup.

## Find all resources in a Namespace ##
This is useful when you can't determine why a namespace will not delete. **You must have a kubectl version > 1.11**.
```
kubectl api-resources --verbs=list --namespaced -o name \
  | xargs -n 1 kubectl get --show-kind --ignore-not-found -l <label>=<arg> -n <namespace>
```

## Automate secret copying between Namespaces ##
Use kubed from appscode. Do not enable the apiserver. Follow [this guide](https://appscode.com/products/kubed/0.9.0/setup/install/).
```
curl -fsSL https://raw.githubusercontent.com/appscode/kubed/0.9.0/hack/deploy/kubed.sh \
    | bash -s -- --cluster-name=<cluster-name> --enable-apiserver=false
```

