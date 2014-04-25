import os
os.system("php kpeople.php")
validNumbers=[]
for file in os.listdir("."):
    if file.endswith(".txt") and file.startswith("RawTrace_"):
        fileNumber = file.lstrip('RawTrace_activityScript').rstrip('.txt')
        if fileNumber != '':
            validNumbers.append(fileNumber)
list = []
for n in validNumbers:
    infile = open("RawTrace_activityScript"+n+".txt", 'r')
    for line in infile:
        line = line.strip('\n')
        if(line[0] == '#'):
            continue
        (t,x,y) = line.split()
        list.append((float(t),float(x),float(y),int(n)))
    infile.close()
list.sort()
outfile = open("result.txt", 'w')
for tuple in list:
    outfile.write("\t".join(str(x) for x in tuple)+"\n")

