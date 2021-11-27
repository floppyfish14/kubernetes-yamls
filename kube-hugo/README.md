Create a static website with Kubernetes
#
1. Create a repo in git
2. Edit deployment.yaml and add your git domain name
3. Create a secret in your namespace with your dockerhub user, pass, and email to allow Kubernetes to pull an image from dockerhub.com, name it regcred.
4. kubectl apply -f ./

*Note: Due to an issue with alpine and kubernetes ensure you leave the 'echo' into /etc/resolv.conf in deployment.yaml*
