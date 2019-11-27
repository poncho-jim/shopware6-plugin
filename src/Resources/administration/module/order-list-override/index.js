import template from "./index.html.twig";
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-list', {
    template,

    computed: {
        orderCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            criteria.addAssociation('paynlTransactions');
            criteria.addAssociation('addresses');
            criteria.addAssociation('salesChannel');
            criteria.addAssociation('orderCustomer');
            criteria.addAssociation('currency');
            criteria.addAssociation('transactions');

            return criteria;
        },
    },

    methods: {
        isPaynlOrderTransaction(item) {
            if (item.extensions.paynlTransactions.length > 0) {
                // console.log(item.transactions[0].stateMachineState.technicalName);
                return true;
            }

            return false;
        },

        isPaynlTransactionAllowedForRefund(statusName) {
            if (statusName === "paid" || statusName === "paid_partially" || statusName === "refunded_partially") {
                return false;
            }

            return true;
        }
    }
});