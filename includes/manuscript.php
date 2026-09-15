<?php
/**
 * EduFlex — landing page content taken from the manuscript.
 *
 * Source: "CAPSTONE30 Final Manuscript Revised.docx", read on 15 September
 * 2026. This file exists so the public page and the manuscript cannot drift:
 * if a module is renamed or a reference is added in the manuscript, it is
 * changed here and the page follows.
 *
 * Everything below is quoted or condensed from the manuscript. Nothing here is
 * invented. Two deliberate omissions, both explained at the point they occur:
 * the comparative matrix, and two sub-modules that are not built.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
   The seven functional modules

   Chapter III, Functional Decomposition Diagram (Figure 11) and the List of
   Modules: seven modules and 34 sub-modules in total.

   TWO SUB-MODULES ARE DELIBERATELY ABSENT FROM THIS ARRAY, because they are
   listed in the manuscript but not implemented, and a public page must not
   claim a feature that does not exist:

     Account and Access Management 5, "Manage Subscription". EduFlex is
     non-commercial per Chapter I. A subscription row is written at
     registration to satisfy the documented ERD, and there is no way to manage
     one because there is nothing to manage.

     Learning Activity and Assessment 2, "Generate Mock Examinations". The only
     activity type the system stores is 'practice_set'. There is no separate
     mock-examination path anywhere in includes/questions.php.

   Both are flagged in CLAUDE.md. Either build them before the freeze or record
   them in Chapter V as future work; do not add them here to make the count
   reach 34.
   ------------------------------------------------------------------------- */

/** @var list<array{title:string, blurb:string, items:list<string>}> */
const MANUSCRIPT_MODULES = [
    [
        'title' => 'Account and Access Management',
        'blurb' => 'Your account, your details, and getting in and out safely.',
        'items' => ['Register an account', 'Sign in', 'Manage profile',
                    'Manage password and settings', 'Sign out'],
    ],
    [
        'title' => 'Learning Resource Management',
        'blurb' => 'The material you provide is what everything else is built from.',
        'items' => ['Upload learning resources', 'Analyze uploaded content',
                    'View and organize resources', 'Update or remove resources'],
    ],
    [
        'title' => 'AI Learning Companion',
        'blurb' => 'Ask about your own material and get an answer grounded in it.',
        'items' => ['Ask resource-based questions', 'Request explanations',
                    'Receive study guidance', 'Review AI-generated responses'],
    ],
    [
        'title' => 'Learning Activity and Assessment',
        'blurb' => 'Practice written from your material and tagged to a level of thinking.',
        'items' => ["Generate practice activities",
                    "Generate Bloom's Taxonomy-aligned questions",
                    'Complete assessments', 'View results and feedback'],
    ],
    [
        'title' => 'Adaptive Learning and Recommendations',
        'blurb' => 'What to study next, decided from what you have actually answered.',
        'items' => ['Analyze performance', 'Determine topic mastery',
                    'Identify weak topics', 'Adjust activity level',
                    'Recommend the next learning activity'],
    ],
    [
        'title' => 'Progress and Engagement',
        'blurb' => 'Where you are, where you were, and what changed.',
        'items' => ['View dashboard', 'Monitor scores and progress',
                    'Review activity history', 'View strengths and weaknesses',
                    'Receive notifications'],
    ],
    [
        'title' => 'Support and Feedback',
        'blurb' => 'Help, and a way to tell the project team when something is wrong.',
        'items' => ['Access help', 'View frequently asked questions',
                    'Submit feedback', 'Report issues',
                    'View Responsible AI reminders'],
    ],
];

/* -------------------------------------------------------------------------
   References

   Chapter II and the References list, deduplicated. The manuscript lists 28
   entries, two of which appear twice: Chen, Chen and Lin (2020), and Kasneci
   et al. (2023). Those duplicates are a manuscript fix, recorded in CLAUDE.md;
   they are collapsed here rather than shown twice on a public page.
   ------------------------------------------------------------------------- */

/** @var list<string> */
const MANUSCRIPT_REFERENCES = [
    'Bond, M., Bedenlier, S., Marín, V. I., & Händel, M. (2020). Emergency remote teaching in higher education: Mapping the first global online semester. International Journal of Educational Technology in Higher Education, 17, 50.',
    'Brusilovsky, P., & Millán, E. (2007). User models for adaptive hypermedia and adaptive educational systems. In P. Brusilovsky, A. Kobsa, & W. Nejdl (Eds.), The Adaptive Web: Methods and Strategies of Web Personalization (pp. 3–53). Springer.',
    'Chen, L., Chen, P., & Lin, Z. (2020). Artificial intelligence in education: A review. IEEE Access, 8, 75264–75278.',
    'Holmes, W., Bialik, M., & Fadel, C. (2019). Artificial Intelligence in Education: Promises and Implications for Teaching and Learning. Center for Curriculum Redesign.',
    'Kirschner, P. A., & Hendrick, C. (2020). How Learning Happens: Seminal Works in Educational Psychology and What They Mean in Practice. Routledge.',
    'Morris, T. H. (2019). Self-directed learning: A fundamental competence in a rapidly changing world. International Review of Education, 65, 633–653.',
    'Pane, J. F., Steiner, E. D., Baird, M. D., & Hamilton, L. S. (2015). Continued Progress: Promising Evidence on Personalized Learning. RAND Corporation.',
    'Panadero, E. (2017). A review of self-regulated learning: Six models and four directions for research. Frontiers in Psychology, 8, 422.',
    'Pintrich, P. R. (2000). The role of goal orientation in self-regulated learning. In M. Boekaerts, P. Pintrich, & M. Zeidner (Eds.), Handbook of Self-Regulation (pp. 451–502). Academic Press.',
    'Sweller, J., van Merriënboer, J. J. G., & Paas, F. (2019). Cognitive architecture and instructional design: 20 years later. Educational Psychology Review, 31, 261–292.',
    'Xie, H., Chu, H. C., Hwang, G. J., & Wang, C. C. (2019). Trends and development in technology-enhanced adaptive learning: A systematic review of the literature. Computers & Education, 140, 103599.',
    'Zawacki-Richter, O., Marín, V. I., Bond, M., & Gouverneur, F. (2019). Systematic review of research on artificial intelligence applications in higher education. International Journal of Educational Technology in Higher Education, 16, 39.',
    'Anderson, L. W., & Krathwohl, D. R. (Eds.). (2001). A taxonomy for learning, teaching, and assessing: A revision of Bloom\'s taxonomy of educational objectives. Longman.',
    'Piaget, J. (1972). The principles of genetic epistemology. Basic Books.',
    'Vygotsky, L. S. (1978). Mind in society: The development of higher psychological processes. Harvard University Press.',
    'Bray, B., & McClaskey, K. (2015). Make learning personal: The what, who, WOW, where, and how of personalized learning. Corwin.',
    'Garrison, D. R. (1997). Self-directed learning: Toward a comprehensive model. Adult Education Quarterly, 48(1), 18–33.',
    'Fernández-Herrero, J. (2024). Evaluating Recent Advances in Affective Intelligent Tutoring Systems: A Scoping Review of Educational Impacts and Future Prospects. Education Sciences, 14(8), 839. https://doi.org/10.3390/educsci14080839',
    'Kasneci, E., Sessler, K., Küchemann, S., Bannert, M., Dementieva, D., Fischer, F., Gasser, U., Groh, G., Günnemann, S., Hüllermeier, E., Krusche, S., Kutyniok, G., Michaeli, T., Nerdinger, F., Pfeffer, J., Poquet, O., Sailer, M., Schmidt, A., Seidel, T., ... Kasneci, G. (2023). ChatGPT for good? On opportunities and challenges of large language models for education. Learning and Individual Differences, 103, 102274.',
    'Zimmerman, B. J. (2002). Becoming a self-regulated learner: An overview. Theory Into Practice, 41(2), 64–70.',
    'Knowles, M. S. (1975). Self-Directed Learning: A Guide for Learners and Teachers. Association Press.',
    'Belcic, I. & Stryker, C. (2023). What is a Workflow Diagram?. IBM. https://www.ibm.com/think/topics/workflow-diagram',
    'Batini, C., Lenzerini, M. & Navathe, S. (1991). Relational database design based on the entity-relationship model. Data & Knowledge Engineering 7(1), pp. 47-83. https://doi.org/10.1016/0169-023X(91)90033-T',
    'Pressman, R. S., & Maxim, B. R. (2020). Software engineering: A practitioner’s approach (9th ed.). McGraw-Hill Education.',
    'Elmasri, R., & Navathe, S. (2000). Fundamentals of database systems (3rd ed.). Addison-Wesley.',
    'Gillis, A. S. & Nolle, T. (2025). What is Network Topology?. TechTarget. https://www.techtarget.com/searchnetworking/definition/network-topology',
];

/**
 * Turn a bare URL inside a reference into a link, and escape everything else.
 *
 * Applied after escaping, never before, so a reference containing markup stays
 * inert. Four of the entries carry a DOI or a publisher URL.
 */
function manuscript_reference_html(string $reference): string
{
    $safe = e($reference);

    return (string) preg_replace(
        '#(https?://[^\s<]+)#',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $safe
    );
}
