# kube-mysql

Create a statefulset mysql instance in k8s
### 

You must have dynamic provisioning enabled for this to work out of the box.

**Label Nodes Accoridingly**
```
for node in worker-0 worker-1 worker-2; do \
kubectl label node ${node} app=mysql failure-domain.beta.kubernetes.io/region=us-west1 failure-domain.beta.kubernetes.io/zone=us-west1-c;
done
```
*Note: region=us-west1 must not have a dash before the number. Take it from experience; you cannot change this fact.*

After using neccessary files from our gitea server:
**Ensure env var, MYSQL_ALLOW_EMPTY_PASSWORD, inside of stateful set is set to 1**

```
kubectl exec -it mysql-0 mysql -- mysql -u root
create user 'root'@'%.us-west1-c.c.mastolab-pos.internal' identified by '';
grant all privileges on *.* to 'root'@'%.us-west1-c.c.mastolab-pos.internal';
flush privileges;
```
This allows any internal mastond-pos node to access the mysql database. *One could use a less limiting host to support multiple regions*
