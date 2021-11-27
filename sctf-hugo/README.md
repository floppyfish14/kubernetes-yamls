##Create a static website with Kubernetes and Hugo

##Prerequisites
1. kubernetes cluster
2. ingress controller
3. public ip and registered dns
4. dockerhub account
5. docker installed locally (to build images)
6. cert-manager (if using ssl certs)

##Let's Get Started
1. Create a repo in git to host the code for the site.
2. Edit deployment.yaml and add your git repo to the initContainer and add your domain name to the hugo container.
3. Create a secret in your namespace with your dockerhub user, pass, and email to allow Kubernetes to pull an image from dockerhub.com, name it regcred.
4. Edit ingress.yaml to reflect your domain name.
5. copy your tls secret to your namespace. We use kubed to do this for us on specific namespaces
6. Build both docker images and push them to your dockerhub account. Ensure that the names are the same as what is listed in deployment.yaml
7. kubectl apply -f ./
