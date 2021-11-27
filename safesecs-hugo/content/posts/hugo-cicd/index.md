---
title: "Hugo Continuous Integration and Deployment"
date: 2019-04-14T21:27:39-07:00
draft: false
type: post
toc: true
---
### Hugo inside of a CI/CD pipeline ###
----
Hugo is a popular open-source framework for building static websites; it's fast, simple, and free as in free beer; *however* there is a catch, due to it's static nature, Hugo must be recompiled every time you make a change to the site. This means that you'd introduce a kink in the development of your website if you are using containerization. *Especially inside of a cloud environment*.

**Think of it this way:**

1. You update some css for your site
*  Now it's time to recompile your custom hugo docker image to reflect the changes
*  After you've pushed the changes to dockerhub, you need to re-apply your deployment manifest to your cluster.
*  Now that you've waited about 10 or so minutes for your change to be made, you realize that you now want to make more changes, and the vicious cycle repeats!

It would look something like this (assuming you set your Dockerfile to pull in the hugo content):
```bash
$ vim static/main.css
$ docker image build -f ./
$ docker tag <image number> <user>/<image-name>:<tag-name>
$ docker push <user>/<image-name>:<tag-name>
$ kubectl delete deployment hugo -n hugo
$ kubectl apply -f deployment.yaml -n hugo
```
This doesn't seem like it's too much of a hastle, until you realize:

* you have to come up with a new tag name for every revision of your website.
* you have to do a lot of waiting for the image to be pushed to docker
* you are creating an unnecessary amount of docker images on your laptop
* you're tired of repeating this sequence: up, up, up, hold left arrow for 3 seconds, backspace, primary paste, enter, up, up, etc.
* **you don't want to write a security blog anymore**

Instead, I introduce my good friend jenkins. ![jenkins_dude](/posts/hugo-cicd/images/jenkins-dude.png#center)

Think of Jenkins as your personal workhorse or slave or whatever; it doesn't matter to me.

Jenkins is a ContinuousIntegration/ContinuousDeployment software that will improve your life as a kubernetes admin. Another pre-req is git, or gitea, or gitlab, etc. All you really need is a *version control system* to host your code inside of, but it must be **reachable from the internet**. Personally, I chose to host my own gitea on my cluster because I would prefer to have complete control over my data. (There will be a post on how to do this later!)

Here is how the CI/CD pipeline will work:

1. You update some css on your site
* You push the contents to a repo inside of gitea
* Jenkins receives a webhook from gitea after every push
* Jenkins then tests the build using a hugo plugin
* If it passes, it deletes a pod on your cluster (causing your replicationset to create another one)
* Your deployment manifests for hugo pull in the code from gitea using an initContainer
* Your Hugo pod then uses the updated contents from the repo 
* A fancy new css style is applied!

Notice the difference here; instead of manually updating and waiting, all you did was:
```bash
$ vim static/main.css
$ git add ./
$ git commit -m "made cool stuff happen"
$ git push origin master
```
That's it. Everything else is handled in the background for you!

#### Prerequisites ####
----
- Kubernetes cluster
- a git repo of some kind
- docker installed locally to push custom images out with
- a jenkins deployment (I'll set that up for you in this post)
- a hugo deployment manifest (I'll also hook you up with that)
- (Optional) a local hugo install to test your site with before you push

### Getting Started ###
----
To begin, create a namespace for your deployments:
```bash
$ kubectl create ns hugo
$ kubectl create ns jenkins
```

Now we need some docker images to pull in content from git and another to run hugo with. Here are some simple docker files to do this with:

**Dockerfile-git**
```dockerfile
From alpine:edge
RUN apk add --update --no-cache \
    git \
```
**Dockerfile-hugo**
```dockerfile
FROM busybox:1.28 AS fetch-standard

ARG VERSION=0.53

ADD https://github.com/gohugoio/hugo/releases/download/v${VERSION}/hugo_${VERSION}_Linux-64bit.tar.gz /hugo.tar.gz
RUN tar -zxvf hugo.tar.gz
RUN ["/hugo", "version"]

FROM busybox:1.28 AS fetch-extended

ARG VERSION=0.53

ADD https://github.com/gohugoio/hugo/releases/download/v${VERSION}/hugo_extended_${VERSION}_Linux-64bit.tar.gz /hugo.tar.gz
RUN tar -zxvf hugo.tar.gz

FROM scratch AS files

COPY --from=fetch-standard /hugo /bin/hugo
COPY --from=fetch-extended /hugo /bin/hugo-extended

FROM scratch

COPY --from=files / /
```
**Dockerfile-jenkins**
```dockerfile
FROM openjdk:8-jdk-alpine

RUN apk add --no-cache git openssh-client curl unzip bash ttf-dejavu coreutils tini hugo

ARG user=jenkins
ARG group=jenkins
ARG uid=1000
ARG gid=1000
ARG http_port=8080
ARG agent_port=50000
ARG JENKINS_HOME=/var/jenkins_home

ENV JENKINS_HOME $JENKINS_HOME
ENV JENKINS_SLAVE_AGENT_PORT ${agent_port}

# Install kubectl
 RUN curl -LO https://storage.googleapis.com/kubernetes-release/release/$(curl -s https://storage.googleapis.com/kubernetes-release/release/stable.txt)/bin/linux/amd64/kubectl && \
     chmod +x ./kubectl && \
     mv ./kubectl /usr/local/bin/kubectl

# Jenkins is run with user `jenkins`, uid = 1000
# If you bind mount a volume from the host or a data container,
# ensure you use the same uid
RUN mkdir -p $JENKINS_HOME \
  && chown ${uid}:${gid} $JENKINS_HOME \
  && addgroup -g ${gid} ${group} \
  && adduser -h "$JENKINS_HOME" -u ${uid} -G ${group} -s /bin/bash -D ${user}

# Jenkins home directory is a volume, so configuration and build history
# can be persisted and survive image upgrades

# `/usr/share/jenkins/ref/` contains all reference configuration we want
# to set on a fresh new installation. Use it to bundle additional plugins
# or config file with your custom jenkins Docker image.
RUN mkdir -p /usr/share/jenkins/ref/init.groovy.d

# jenkins version being bundled in this docker image
ARG JENKINS_VERSION
ENV JENKINS_VERSION ${JENKINS_VERSION:-2.164.1}

# jenkins.war checksum, download will be validated using it
ARG JENKINS_SHA=65543f5632ee54344f3351b34b305702df12393b3196a95c3771ddb3819b220b

# Can be used to customize where jenkins.war get downloaded from
ARG JENKINS_URL=https://repo.jenkins-ci.org/public/org/jenkins-ci/main/jenkins-war/${JENKINS_VERSION}/jenkins-war-${JENKINS_VERSION}.war

# could use ADD but this one does not check Last-Modified header neither does it allow to control checksum
# see https://github.com/docker/docker/issues/8331
RUN curl -fsSL ${JENKINS_URL} -o /usr/share/jenkins/jenkins.war \
  && echo "${JENKINS_SHA}  /usr/share/jenkins/jenkins.war" | sha256sum -c -

ENV JENKINS_UC https://updates.jenkins.io
ENV JENKINS_UC_EXPERIMENTAL=https://updates.jenkins.io/experimental
ENV JENKINS_INCREMENTALS_REPO_MIRROR=https://repo.jenkins-ci.org/incrementals
RUN chown -R ${user} "$JENKINS_HOME" /usr/share/jenkins/ref

# for main web interface:
EXPOSE ${http_port}

# will be used by attached slave agents:
EXPOSE ${agent_port}

ENV COPY_REFERENCE_FILE_LOG $JENKINS_HOME/copy_reference_file.log

USER ${user}

COPY jenkins-support /usr/local/bin/jenkins-support
COPY jenkins.sh /usr/local/bin/jenkins.sh
COPY tini-shim.sh /bin/tini
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/jenkins.sh"]

# from a derived Dockerfile, can use `RUN plugins.sh active.txt` to setup /usr/share/jenkins/ref/plugins from a support bundle
COPY plugins.sh /usr/local/bin/plugins.sh
COPY install-plugins.sh /usr/local/bin/install-plugins.sh

# Install wanted plugins
RUN /usr/local/bin/install-plugins.sh kubernetes:${VERSION} gogs-webhook:latest kubernetes-cli:latest hugo:latest github:latest gitea:latest
```
### Build and Push docker images ###
----
A default Jenkins install will not be useful for our scenario; instead, download **[this tar archive](https://safesecs.io/posts/hugo-cicd/kube-jenkins.tar.gz)** and extract it to a directory on your machine. This will create a docker image with gogs, gitea, git, hugo, and kubernetes plugins pre-installed. Pretty sweet huh?
```bash
$ mkdir -p /tmp/{jenkins,alpine-git,hugo}
$ cd /tmp/jenkins
$ wget https://safesecs.io/posts/hugo-cicd/kube-jenkins.tar.gz
$ tar -zxvf kube-jenkins.tar.gz
$ docker image build -f ./Dockerfile
$ docker tag <image number> <docker user>/kube-jenkins:latest
$ docker push <docker user>/kube-jenkins:latest
```
```bash
$ cd /tmp/alpine-git
$ docker image build -f Dockerfile-git
$ docker tag <image number> <docker user>/alpine-git:latest
$ docker push <docker user>/alpine-git:latest
```
```bash
$ cd /tmp/hugo
$ docker image build -f Dockerfile-hugo
$ docker tag <image number> <docker user>/hugo:latest
$ docker push <docker user>/hugo:latest
```
We have just created three docker images. One for jenkins (with kubectl, hugo, git, and gitea plugins), another with hugo to host our site on, and a git image to pull in the content for hugo.

### Push your hugo code to your repository ###
----
For this to work, we need a repository to pull from. Issue:
```bash
$ hugo new site myhugosite
$ cd myhugosite
$ hugo new posts/myfirstpost.md
$ echo "HELLOOO!" >> content/posts/myfirstpost.md
$ git init ./
$ git remote add origin https://<yourdomain>/<username>/<reponame>.git
$ git add ./
$ git commit -m "initial commit"
$ git push origin master
```
To begin, create a repository in whichever Version Control System you'd prefer; I'll wait while you do that real quick.

Now we can populate a directory, called myhugosite, with a barebones hugo structure. Then we place a post inside for fun. After that initialize the site as a git repo and push its content to our repository.
### Deploy Jenkins on kubernetes ###
----
**resolv-conf.yaml**
```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: resolv-conf
  labels:
    app: jenkins
data:
  resolv.conf: |
    search jenkins.svc.cluster.local svc.cluster.local cluster.local
    nameserver 10.32.0.10
    nameserver 8.8.8.8
```
This configmap is needed for jenkins to reach outside of the cluster. Because we've created an alpine version of Jenkins, we are using muslc instead of glibc; this causes issues in external domain name resolution by default. The reason for this error has to do with ndots=5 being placed inside of /etc/resolv.conf **[read this article](https://wiki.musl-libc.org/functional-differences-from-glibc.html#Name-Resolver/DNS)** to find out more. To combat this, apply resolv-conf.yaml in the jenkins namespace and call it in the deployment manifest. If needed, modify the file.

**service.yaml**
```yaml
apiVersion: v1
kind: Service
metadata:
  name: jenkins
spec:
  ports:
  - port: 8080
    targetPort: 8080
  selector:
    app: jenkins
```
This will create a clusterIP service on port 8080. To use this, I recommend using the nginx ingress-controller (covered in **[this](https://safesecs.io/posts/ingress-controller)** post. If you do not believe this will work for your setup, feel free to change the type to loadbalancer or nodeport.

**deployment.yaml**
```yaml
apiVersion: extensions/v1beta1
kind: Deployment
metadata:
  name: jenkins
spec:
  replicas: 1
  template:
    metadata:
      labels:
        app: jenkins
    spec:
      initContainers:
      - name: init-disk
        image: busybox:latest
        command: ["/bin/sh"]
        args: ["-c", "chown -R 1000:1000 /var/jenkins_home"]
        volumeMounts:
        - name: jenkins-home
          mountPath: /var/jenkins_home
          readOnly: false
      containers:
      - name: jenkins
        image: <docker user>/kube-jenkins:hugo
        imagePullPolicy: Always
        env:
          - name: JAVA_OPTS
            value: -Djenkins.install.runSetupWizard=false
        ports:
        - name: jenkins-http
          containerPort: 8080
        - name: jnlp-port
          containerPort: 50000
        volumeMounts:
        - name: jenkins-home
          mountPath: /var/jenkins_home
        volumeMounts:
        - name: resolv-conf
          mountPath: /etc/resolv.conf
          subPath: resolv.conf
      imagePullSecrets:
      - name: regcred
      volumes:
      - name: jenkins-home
        emptyDir: {}
      - name: resolv-conf
        configMap:
          name: resolv-conf
```
Our deployment.yaml will pull in the Jenkins image we created earlier and begin running it. We disable the setupwizard because we have already included all of the plugins we need and soon we will have a configuration manifest that sets up our cluster for us!

**configmap.yaml**
```xml
apiVersion: v1
kind: ConfigMap
metadata:
  name: jenkins-config
  labels:
    app: jenkins
data:
  config.xml: |
    <?xml version='1.1' encoding='UTF-8'?>
    <hudson>
      <disabledAdministrativeMonitors/>
      <version>2.164.1</version>
      <installStateName>RUNNING</installStateName>
      <numExecutors>4</numExecutors>
      <mode>NORMAL</mode>
      <useSecurity>true</useSecurity>
      <authorizationStrategy class="hudson.security.FullControlOnceLoggedInAuthorizationStrategy">
        <denyAnonymousReadAccess>true</denyAnonymousReadAccess>
      </authorizationStrategy>
      <securityRealm class="hudson.security.HudsonPrivateSecurityRealm">
        <disableSignup>false</disableSignup>
        <enableCaptcha>false</enableCaptcha>
      </securityRealm>
      <disableRememberMe>false</disableRememberMe>
      <projectNamingStrategy class="jenkins.model.ProjectNamingStrategy$DefaultProjectNamingStrategy"/>
      <workspaceDir>${JENKINS_HOME}/workspace/${ITEM_FULL_NAME}</workspaceDir>
      <buildsDir>${ITEM_ROOTDIR}/builds</buildsDir>
      <markupFormatter class="hudson.markup.EscapedMarkupFormatter"/>
      <jdks/>
      <viewsTabBar class="hudson.views.DefaultViewsTabBar"/>
      <myViewsTabBar class="hudson.views.DefaultMyViewsTabBar"/>
      <clouds>
        <org.csanchez.jenkins.plugins.kubernetes.KubernetesCloud plugin="kubernetes@1.14.9">
          <name>kubernetes</name>
          <defaultsProviderTemplate></defaultsProviderTemplate>
          <templates>
            <org.csanchez.jenkins.plugins.kubernetes.PodTemplate>
              <inheritFrom></inheritFrom>
              <name>jenkins-slave</name>
              <namespace>jenkins</namespace>
              <privileged>false</privileged>
              <capOnlyOnAlivePods>false</capOnlyOnAlivePods>
              <alwaysPullImage>false</alwaysPullImage>
              <instanceCap>2147483647</instanceCap>
              <slaveConnectTimeout>100</slaveConnectTimeout>
              <idleMinutes>0</idleMinutes>
              <activeDeadlineSeconds>0</activeDeadlineSeconds>
              <label>jenkins-slave</label>
              <nodeSelector></nodeSelector>
              <nodeUsageMode>EXCLUSIVE</nodeUsageMode>
              <customWorkspaceVolumeEnabled>false</customWorkspaceVolumeEnabled>
              <workspaceVolume class="org.csanchez.jenkins.plugins.kubernetes.volumes.workspace.EmptyDirWorkspaceVolume">
                <memory>false</memory>
              </workspaceVolume>
              <volumes/>
              <containers>
                <org.csanchez.jenkins.plugins.kubernetes.ContainerTemplate>
                  <name>jenkins-slave</name>
                  <image>jenkins/jnlp-slave</image>
                  <privileged>false</privileged>
                  <alwaysPullImage>true</alwaysPullImage>
                  <workingDir>/home/jenkins</workingDir>
                  <command>/bin/sh -c</command>
                  <args>cat</args>
                  <ttyEnabled>true</ttyEnabled>
                  <resourceRequestCpu></resourceRequestCpu>
                  <resourceRequestMemory></resourceRequestMemory>
                  <resourceLimitCpu></resourceLimitCpu>
                  <resourceLimitMemory></resourceLimitMemory>
                  <envVars/>
                  <ports/>
                  <livenessProbe>
                    <execArgs></execArgs>
                    <timeoutSeconds>0</timeoutSeconds>
                    <initialDelaySeconds>0</initialDelaySeconds>
                    <failureThreshold>0</failureThreshold>
                    <periodSeconds>0</periodSeconds>
                    <successThreshold>0</successThreshold>
                  </livenessProbe>
                </org.csanchez.jenkins.plugins.kubernetes.ContainerTemplate>
              </containers>
              <envVars/>
              <annotations/>
              <imagePullSecrets/>
              <nodeProperties/>
              <yaml></yaml>
              <podRetention class="org.csanchez.jenkins.plugins.kubernetes.pod.retention.Default"/>
            </org.csanchez.jenkins.plugins.kubernetes.PodTemplate>
          </templates>
          <serverUrl>https://kubeapiserverIP:6443</serverUrl>
          <serverCertificate> Paste your kubectl user certificate here; not the CA cert but the cert for your user.</serverCertificate>
          <skipTlsVerify>true</skipTlsVerify>
          <addMasterProxyEnvVars>false</addMasterProxyEnvVars>
          <capOnlyOnAlivePods>false</capOnlyOnAlivePods>
          <namespace>jenkins</namespace>
          <jenkinsUrl>https://yourjenkinsurl</jenkinsUrl>
          <credentialsId>kubeconfig</credentialsId>
          <containerCap>10</containerCap>
          <retentionTimeout>5</retentionTimeout>
          <connectTimeout>5</connectTimeout>
          <readTimeout>15</readTimeout>
          <usageRestricted>false</usageRestricted>
          <maxRequestsPerHost>32</maxRequestsPerHost>
          <waitForPodSec>600</waitForPodSec>
          <podRetention class="org.csanchez.jenkins.plugins.kubernetes.pod.retention.Never"/>
        </org.csanchez.jenkins.plugins.kubernetes.KubernetesCloud>
      </clouds>
      <quietPeriod>5</quietPeriod>
      <scmCheckoutRetryCount>0</scmCheckoutRetryCount>
      <views>
        <hudson.model.AllView>
          <owner class="hudson" reference="../../.."/>
          <name>all</name>
          <filterExecutors>false</filterExecutors>
          <filterQueue>false</filterQueue>
          <properties class="hudson.model.View$PropertyList"/>
        </hudson.model.AllView>
      </views>
      <primaryView>all</primaryView>
      <slaveAgentPort>50000</slaveAgentPort>
      <label></label>
      <nodeProperties/>
      <globalNodeProperties/>
```
Wow that's long! Pay particular attention to \<serverUrl\> and \<serverCertificate\> and \<jenkinsURL\> tags as these need to be custom for this configmap to work properly. Other noteworthy aspects of this config are:

* security is enabled (sign up allowed)
* Your kubernetes cluster and pod template are already in place
* We have allowed jenkins to use 4 executors by default
* Your jenkins version may be different, check the \<version\> tag at the top

All that is left for you to do is apply the files. **In case you copy and pasted the above configmap, note that you need to edit sections of the configmap for your own cluster!**

Issue:
```
~$ kubectl apply -f service.yaml configmap.yaml resolv-conf.yaml deployment.yaml -n jenkins
```
Apply an ingress or use a loadbalancer or nodeport to access your new jenkins server.

### Create some jobs in jenkins ###
---
-<img src="/posts/hugo-cicd/images/jenkins-1.png"></img>
Log into Jenkins and go to the Dashboard. Select *New Item* in the top left hand corner.

<img src="/posts/hugo-cicd/images/jenkins-2.png"></img>
Enter a title for your new job and select it as a freestyle project.

<img src="/posts/hugo-cicd/images/jenkins-3.png"></img>
Give your job a short description and select *Discard old builds*. Scroll down to Source Code Management.

<img src="/posts/hugo-cicd/images/jenkins-4.png"></img>
Enter your repo's url and create a user/pass key to authenticate with. Inside of Build Triggers, select *Build when a change is pushed to Gogs*.

<img src="/posts/hugo-cicd/images/jenkins-5.png"></img>
Under Build, open the dropdown menu and select *hugo builder*. For Post Build Actions, open the dropdown:

* Select *Build another Project*
* enter the name of the next project you will be creating (i.e deploy hugo)
* select *trigger only if build is stable*
* save the project

<img src="/posts/hugo-cicd/images/jenkins-6.png"></img>
Create a second project using the name you entered into the final step of the last project. This will also be a frestyle project.

<img src="/posts/hugo-cicd/images/jenkins-7.png"></img>
Enter a short description and select discard old builds.

<img src="/posts/hugo-cicd/images/jenkins-8.png"></img>
Under Build Triggers, select *Build after another Project is built*. Then specify the name of the last project; select *Trigger only if build is stable*. Scroll down to your Build Environment and select *Setup Kubernetes CLI (kubectl)*. 

Now enter your kubernetes-api endpoint (i.e https://127.0.0.1:6443). *If you aren't sure, look inside of your ~/.kube/config.* This file also has the certificate authority's certificate inside of it. Enter the CA's certificate below the endpoint.

<img src="/posts/hugo-cicd/images/jenkins-9.png"></img>
Underneath Build, select *Execute Shell* and enter:
```
kubectl -n hugo delete po $(kubectl get po -n hugo | grep hugo | cut -d' ' -f1);
```
This grabs the pod name inside of the hugo namespace and deletes it. The thought behind this is that our replicationset will then create another pod to take its place; effectively forcing an update to your website. Save the project.

Now we need to setup webhooks inside of our git repo. For gitea, the url will be as such:
```
https://yourjenkinsurl.com/gogs-webhook/?job=your_build_hugo_job_name
```

### Deploy hugo on kubernetes ###
It is possible to deploy custom images to your kubernetes cluster, all you need to do is specify some credentials for kubernetes to use to access your docker images.

**regcred.yaml**
```yaml
apiVersion: v1
kind: Secret
metadata:
  name: regcred
type: Opaque
data:
  username: <your base64 encoded docker username>
  password: <your base64 encoded docker password>
  email: <your base64 encoded docker email>
```
To encode your password to base64, issue:
```
$ echo password | base64
cGFzc3dvcmQK
$
```

Pushing your credentials to a cluster in the wild is a bold move cotton; however, don't frett! Your communications through kubectl should be encrypted with an SSL certificate and etcd is where secrets are stored inside of a cluster. Etcd does not allow someone to display the content of a secret even if they use kubectl to describe the secret. Let's apply these to our cluster:
```
~$ kubectl apply -f regcred.yaml -n hugo
```
Okay, with the foundations laid we can finally start running hugo. Here's some manifests:

**service.yaml**
```yaml
kind: Service
apiVersion: v1
metadata:
  name: hugo
spec:
  selector:
    app: hugo
  ports:
  - name: hugo
    port: 443
    targetPort: 443
```
After applying this manifest you will have a clusterIP service running inside of the hugo namespace. Use an ingress to route traffic to your service; *or* make the service into a nodePort and access the content from there. I would recommend creating an nginx ingress controller inside of your cluster to route traffic behind a load balancer. (Read **[this post](https://safesecs.io/posts/ingress-controller)** if you'd like to setup an ingress controller)

**deployment.yaml**
```yaml
apiVersion: extensions/v1beta1
kind: Deployment
metadata:
  name: hugo
spec:
  replicas: 1
  template:
    metadata:
      labels:
        app: hugo
    spec:
      initContainers:
      - name: alpine-git
        image: <docker user>/alpine-git:latest
        imagePullPolicy: Always
        command: ["/bin/sh"]
        # due to alpine using muslc instead of glibc, outward dns resolution does not happen properly by default.
        # if you want your git image to be able to reach outside the cluster you should overwrite the contents of /etc/resolv.conf
        args: ["-c", "echo 'nameserver 8.8.8.8' > /etc/resolv.conf && git clone https://<your github repo> /tmp/src/hugo"]
        volumeMounts:
        - name: source-content
          mountPath: "/tmp/src"
      imagePullSecrets:
      - name: regcred
      containers:
      - name: hugo
        image: <docker user>/hugo:latest
        imagePullPolicy: IfNotPresent
        ports:
        - containerPort: 443
        command: ["hugo"]
        args: ["server", "-p=443", "--bind=0.0.0.0", "--baseUrl=https://<your domain name>", "-s=/tmp/src/hugo/"]
        volumeMounts:
        - name: source-content
          mountPath: "/tmp/src"
      imagePullSecrets:
      - name: regcred
      volumes:
      - name: source-content
        emptyDir: {}
```
This manifest will connect to dockerhub and pull in the images we just created. Note that you must have regcred.yaml applied inside of the namespace you're using for this to work! We create an initContainer with the alpine-git image. "alpine-git" clones the repository that will contain our hugo code into an emptyDir; the emptyDir is then mounted inside of the final hugo container. 

initContainers run before the actual container, making it incredibly useful for this situation. Next, we create a container to run hugo for us. **Note that you must include --baseUrl if you plan on using https or your site will have mixed content and will not properly load in most browsers.**

For the brevity of this post, I will assume your traffic is being routed properly to your services. If you would like to create an ingress controller for your cluster, follow [this post](https://safesecs.io/ingress-controller). 

Watch as everything comes together:
```zsh
$ kubectl apply -f service.yaml deployment.yaml -n hugo
```

### Profit ###
----
Now that we've gone through this incredibly intricate process of setting up a ci/cd pipeline with kubernetes, hugo, gitea, and jenkins we should be happy that we'll never have to do that again!

Now the only things necessary for an update to your awesome blog should be a simple push to your git repo and then a short (about 1 minute) wait. I have tried to cover everything I could while remaining relatively brief in the description of what's happening. If you've noticed an error or would like further explanation, please **[email me](mailto:mfish551.mf@gmail.com)** or leave a comment.
