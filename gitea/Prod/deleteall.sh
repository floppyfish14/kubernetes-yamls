#!/bin/bash
kubectl delete deployment gitea
kubectl delete svc gitea-web
kubectl delete svc gitea-ssh
kubectl delete svc gitea-letsencrypt
kubectl delete configmap gitea-config
kubectl delete storageclass local-storage
kubectl delete ingress gitea
kubectl delete pvc gitea
kubectl delete pv gitea
