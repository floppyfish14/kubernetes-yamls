---
title: "Nginx Ingress Controller"
date: 2019-05-08T09:41:41-07:00
toc: true
type: post
draft: false
---
### Ingress Controllers in Kubernetes ###
---
When I first started with Kubernetes, I was all about NodePort services. They're convienient and easy enough to create; however, soon enough I realized that this was not a scalable solution. NodePort services are ephemeral, meaning your client would never know which port his application resides under (which he shouldn't have to know anyways) and the port numbers are randomly assigned within your specified range (i.e 32700-65535). That isn't exactly convient for developers or end-users.

*Enter the Nginx Ingress Controller.* An Ingress Controller exposes HTTP and HTTPS routes from outside the cluster to services within the cluster, traffic routing is defined by the Ingress resource (i.e Kind: Ingress). For further reading check out the **[kubernetes docs](https://kubernetes.io/docs/concepts/services-networking/ingress/)**.

Today we will be creating an Nginx Ingress Controller deployment; if you require a highly available solution, I recommend creating the daemonset instead. The goal is to have all of your web apps available from the internet behind ports 80 and 443.

### Overview ###
---
Here is an *incredibly* techincal drawing of how the traffic should be routed to your services:
```
        Internet
    -------|----------------------------------------
    [ L4 LoadBalancer ] --> [ Cluster Worker Nodes ]
       (443 and 80)               (443 and 80)
                            -----------|-----------
     (Your ClusterIP         [ Ingress Controller ]
      services can be                  |
      any port you wish       [ Ingress Resource ]
      to specify)           -----------|-----------
                             [ ClusterIP Services ]
                          (443 or 80 -> 6443 or 8080)
```
Seems pretty simple! The most important thing to realize here is that you now have a static IP and port to reach all of your applications on. The layer 4 LoadBalancer Provides the IP; your ingress controller coupled with the ingress resource transfers the outward ports to the local ClusterIP port number.

### Prerequisites ###
---
* Kubernetes Cluster
- An available Static IP (if using a deployment vice daemonset)
- Administrator Access to your Kubernetes API

### Getting Started ###
---
To begin, let's create the 'test' namespace and git the manifests we'll need for our new resources. We don't create the nginx-ingress namespace right now because the manifests create it for us.
```
$ kubectl create ns test
$ cd /tmp && git clone https://github.com/nginxinc/kubernetes-ingress.git
```
*The default-server-secret manifest contains a default TLS key and cert, it is recommended that you use your own certificate and key instead.* 

Now we will create the resources inside of the nginx-ingress namespace (no need to specify the namespace), issue:
```
$ cd /tmp/kubernetes-ingress/deployments/
$ kubectl apply -f common/ns-and-sa.yaml
$ kubectl apply -f default-server-secret.yaml
$ kubectl apply -f common/nginx-config.yaml
```
We have just created the ingress controller's namespace, service account, default server secret, and configuration. Next we create a cluster role and bind it to the service account we just created. *You must be a cluster admin for this step to complete succesfully*
```
$ kubectl apply -f rbac/rbac.yaml
```
Now we have the option of creating a *Deployment* or a *DaemonSet*. A deployment can be used to dynamically change the number of Ingress Controller Replicas on your cluster; however, they may not all be scheduled on seperate nodes. Theoretically, if all of your ingress controllers were scheduled on a single node and that node goes down, users would lose access to the services behind the Ingress resources you have defined.

If you need High Availability and desire assurance that you can always reach your services, it is recommended to use a DaemonSet because this will schedule the controller to be deployed once on every worker node in your cluster.

### Deploy the Ingress Controller ###
---
```
$ kubectl apply -f deployment/nginx-ingress.yaml
$ kubectl get po -n nginx-ingress -w
```
After you apply the manifest, watch the pod being created to verify that you now have an ingress controller on your cluster. Now we need access to it.

### Accessing the Controller ###
---
If you deployed a DaemonSet, you can now access your controller from port 80 and 443 of any worker node in your cluster, it is still recommended to use a Layer4 LoadBalancer to access these nodes.

If instead you created a deployment, you must now apply service manifests to access your ingress controller. If the platform being used can support, it is recommended to create a LoadBalancer service for ease of deployment. If you are using a DaemonSet and loadbalancer service, continue to **[Create an Ingress Resource](http://localhost:8080/posts/ingress-controller/#create-an-ingress-resource)**.

Most baremetal clusters do not have this ability, if that is the case, you can still work around this by editing your kubernetes-api. SSH into your controller nodes and execute the following on each:
```
$ sudo vim /etc/systemd/system/kube-apiserver.service
```
Change the defined node port range to this:
```
--service-node-port-range=0-32767 \
```
Now execute:
```
$ sudo systemctl daemon-reload && sudo systemctl restart kube-apiserver;
```
This changed the range of allowed NodePort assignments from the default (32767-65535) to 0-32767; there are some security implications here, so if you are not comfortable with allowing your containers access to low-level ports, do not apply this work-around. Keep in mind that any NodePort service you create (without specifying the port on the node) now has the chance to be running on a priveleged port; therefore, you are introducing some level of insecurity to your cluster.

**Note: If using GKE or Amazon EKS and you elected against using a loadbalancer service, you need to edit the manifest for your kubeapi server, as opposed to the service file from the example.**

Now edit the manifest for the NodePort service to ensure it reads:
```yaml
apiVersion: v1
kind: Service
metadata:
  name: nginx-ingress
  namespace: nginx-ingress
spec:
  type: NodePort
  ports:
  - port: 80
    targetPort: 80
    nodePort: 80
    protocol: TCP
    name: http
  - port: 443
    targetPort: 443
    nodePort: 443
    protocol: TCP
    name: https
  selector:
    app: nginx-ingress
```
Apply the manifest.
```
$ kubectl apply -f service/nodeport.yaml
```

Okay, now we have access to the ingress controller from the outside world; be that as it may, it is now time to create a LoadBalancer to ensure access to this resource. The reasoning behind this is that many cloud providers do not provide static external IPs for the nodes inside of a cluster (GCP is one of these offenders), so if you were to point your DNS record to the current external IP of your worker node, it may change at the next reboot of the node. Clients would frown upon this. 

Save yourself the headache and create a static ip that leverages a Layer4 LoadBalancer to forward the traffic to an InstanceGroup which contains all of your worker nodes; this creates a blanket of security that you and your clients can wrap themselves up inside of and *sleep soundly* because of.

Here is an *incredibly* technical drawing to illustrate:
```
                       Static IP
                           |
                  L4 LB (443 and 80)
                  ---------|--------
                    Instance Group
                  --|------|-----|--
                    w0    w1     w2
                  ---------|--------
                  Ingress Controller
```
### Create an Ingress Resource ###
---
After all of this work, we can now bask in the glory of our creation. Apply this yaml file in the test namespace:

**nginx.yaml**
```yaml
apiVersion: extensions/v1beta1
kind: Ingress
metadata:
  name: ingress-gitea
  annotations:
    kubernetes.io/ingress.class: nginx
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  rules:
  - host: example.com 
    http:
      paths:
      - backend:
          serviceName: nginx
          servicePort: 80
---
apiVersion: extensions/v1beta1
kind: Deployment
metadata:
  name: nginx
spec:
  replicas: 1
  template:
    metadata:
      labels:
        app: nginx
    spec:
      Containers:
      - name: nginx
        image: nginx:latest
        imagePullPolicy: Always
        ports:
        - containerPort: 80
---
apiVersion: v1
kind: Service
metadata:
  name: nginx
  labels:
    name: nginx
spec:
  selector:
    service: nginx
  ports:
    - name: nginx
      port: 80
      targetPort: 80
```
Now apply this manifest using:
```
$ kubectl apply -f nginx.yaml -n test
```
If all has gone well, your Ingress Controller will have registered the new Ingress resource and chosen how to route traffic to the service so that you can reach the deployment from the internet. Using your browser, navigate to the website you specified underneath 'host:' inside the ingress manifest. You should now see the faithful:
![It Works!](/posts/ingress-controller/itworks.png#center)

### The Foundation has Been Layed ###
---
Creating an ingress controller has opened the door to many possibilites for your cluster. You can now feasibly enable automatic certificate retrieval by utilizing CertManager and LetsEncrypt! on top of Ingress. Furthermore, this solution is scalable as a result of the host definitions in the Ingress resource; multiple websites or subdomains or api endpoints can be hosted inside one cluster or even namespace. Enjoy the flexibility you have established for yourself!
